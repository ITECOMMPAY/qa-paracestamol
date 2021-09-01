<?php

namespace Paracetamol\Helpers;

use Codeception\Configuration;
use Codeception\Util\PathResolver;
use Paracetamol\Exceptions\InvalidArgumentException;
use Paracetamol\Exceptions\UsageException;
use Paracetamol\Settings\ICodeceptionHelperSettings;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

trait CodeceptionSettingsParser
{
    abstract protected function getSettings() : ICodeceptionHelperSettings;

    protected function resolveCodeceptionBinPath() : void
    {
        $codeceptionBinary = $this->findCodeceptionBinPath();

        if ($codeceptionBinary === null)
        {
            throw new InvalidArgumentException('Cannot find the /vendor/bin/codecept path');
        }

        $this->getSettings()->setCodeceptionBinPath($codeceptionBinary);
    }

    protected function loadCodeceptionConfig(InputInterface $input) : void
    {
        $configPath = $this->findConfigPath($input);

        if ($configPath === null)
        {
            throw new InvalidArgumentException('The given path does not contain a codeception.yml file or the file is not accessible');
        }

        $this->getSettings()->setCodeceptionConfigPath($configPath);

        $parsedConfig = Yaml::parseFile($this->getSettings()->getCodeceptionConfigPath());

        $this->getSettings()->setCodeceptionConfig($parsedConfig);

        $this->setNamespace();

        $this->setTestProjectPath();

        $this->overrideCodeceptionConfigFromOptions($input);

        $this->overrideCodeceptionConfigFromEnv($input);

        $this->setTestsPath();

        $this->setSupportPath();

        $this->setOutputPath();
    }

    protected function setTestProjectPath() : void
    {
        $projectDir = realpath(dirname($this->getSettings()->getCodeceptionConfigPath()));
        $this->getSettings()->setTestProjectPath($projectDir);
    }

    protected function setTestsPath() : void
    {
        $testsSubdir = $this->getSettings()->getCodeceptionConfig()['paths']['tests'];

        if ($testsSubdir === '.')
        {
            $testsSubdir = $this->getSettings()->getSuite();
        }

        $this->getSettings()->setTestsPath(realpath($this->getSettings()->getTestProjectPath() . DIRECTORY_SEPARATOR . $testsSubdir));
    }

    protected function setSupportPath() : void
    {
        $supportSubdir = $this->getSettings()->getCodeceptionConfig()['paths']['support'];

        if (!PathResolver::isPathAbsolute($supportSubdir))
        {
            $supportSubdir = realpath($this->getSettings()->getTestProjectPath() . DIRECTORY_SEPARATOR . $supportSubdir);
        }

        $this->getSettings()->setSupportPath($supportSubdir);
    }

    protected function setOutputPath() : void
    {
        $outputSubdir = $this->getSettings()->getCodeceptionConfig()['paths']['output'];

        if (!PathResolver::isPathAbsolute($outputSubdir))
        {
            $outputSubdir = realpath($this->getSettings()->getTestProjectPath() . DIRECTORY_SEPARATOR . $outputSubdir);
        }

        $this->getSettings()->setOutputPath($outputSubdir);
    }

    protected function setNamespace() : void
    {
        $this->getSettings()->setNamespace($this->getSettings()->getCodeceptionConfig()['namespace'] ?? '');
    }

    protected function overrideCodeceptionConfigFromEnv($input) : void
    {
        $envOptions = !empty($input->getOption('env')) ? $input->getOption('env') : $this->getSettings()->getEnv();

        if (empty($envOptions))
        {
            return;
        }

        $envNames = [];

        foreach ($envOptions as $envOption)
        {
            $envNames []= explode(',', $envOption);
        }

        $envNames = array_merge(...$envNames);

        sort($envNames, SORT_NATURAL);

        $this->getSettings()->setEnv($envNames);

        $envs = $this->loadEnvConfigs();

        foreach ($this->getSettings()->getEnv() as $envName)
        {
            if (!isset($envs[$envName]))
            {
                throw new UsageException('There is no env with name: ' . $envName);
            }

            $env = $envs[$envName];

            if (isset($env['paths']['output']))
            {
                throw new UsageException('Please remove .paths.output from the env: ' . $envName);
            }

            $updatedConfig = Configuration::mergeConfigs($this->getSettings()->getCodeceptionConfig(), $env);

            $this->getSettings()->setCodeceptionConfig($updatedConfig);
        }
    }

    protected function loadEnvConfigs() : array
    {
        $envsDir = $this->getSettings()->getCodeceptionConfig()['paths']['envs'] ?? '';

        if (empty($envsDir))
        {
            return [];
        }

        $path = realpath($this->getSettings()->getTestProjectPath() . DIRECTORY_SEPARATOR . $envsDir);

        if ($path === false)
        {
            return [];
        }

        $envFiles = Finder::create()
                          ->files()
                          ->name('*.yml')
                          ->in($path)
                          ->depth('< 2');

        $envConfig = [];
        /** @var SplFileInfo $envFile */
        foreach ($envFiles as $envFile) {
            $env = str_replace(['.dist.yml', '.yml'], '', $envFile->getFilename());
            $envConfig[$env] = [];
            $envPath = $path;
            if ($envFile->getRelativePath()) {
                $envPath .= DIRECTORY_SEPARATOR . $envFile->getRelativePath();
            }
            foreach (['.dist.yml', '.yml'] as $suffix) {

                $file = $envPath . DIRECTORY_SEPARATOR . $env . $suffix;

                if (!file_exists($file))
                {
                    continue;
                }

                $envConf = Yaml::parseFile($file);

                if ($envConf === null) {
                    continue;
                }
                $envConfig[$env] = Configuration::mergeConfigs($envConfig[$env], $envConf);
            }
        }

        return $envConfig;
    }

    protected function overrideCodeceptionConfigFromOptions(InputInterface $input) : void
    {
        $override = !empty($input->getOption('override')) ? $input->getOption('override') : $this->getSettings()->getOverride();

        $configPatch = [];

        foreach ($override as $option)
        {
            $keys = explode(': ', $option);

            if (count($keys) < 2)
            {
                throw new InvalidArgumentException('override option should have config passed as "key:value"');
            }

            $value = array_pop($keys);
            $yaml = '';

            for ($ind = 0; count($keys); $ind += 2)
            {
                $yaml .= "\n" . str_repeat(' ', $ind) . array_shift($keys) . ': ';
            }

            $yaml .= $value;

            try
            {
                $config = Yaml::parse($yaml);
            }
            catch (ParseException $e)
            {
                throw new \Codeception\Exception\ParseException("Overridden config can't be parsed: \n$yaml\n" . $e->getParsedLine());
            }

            $configPatch []= $config;
        }

        $configPatch = array_merge_recursive(...$configPatch);

        $updatedConfig = Configuration::mergeConfigs($this->getSettings()->getCodeceptionConfig(), $configPatch);

        $this->getSettings()->setCodeceptionConfig($updatedConfig);

        $overrideWithoutOutputPath = [];

        foreach ($override as $option)
        {
            if (preg_match('%paths:\s*output:%mi', $option) === 1)
            {
                continue;
            }

            $overrideWithoutOutputPath []= $option;
        }

        $this->getSettings()->setOverride($overrideWithoutOutputPath);
    }

    protected function findConfigPath(InputInterface $input) : ?string
    {
        $config = $input->getArgument('config') . DIRECTORY_SEPARATOR . 'codeception.yml';

        if (!file_exists($config))
        {
            $config = getcwd() . DIRECTORY_SEPARATOR . $config;

            if (!file_exists($config))
            {
                return null;
            }
        }

        $config = realpath($config);

        if ($config === false)
        {
            return null;
        }

        return $config;
    }

    protected function findCodeceptionBinPath() : ?string
    {
        $vendorDir = $this->findVendorPath(__DIR__, 10);

        if ($vendorDir === null)
        {
            return null;
        }

        $codeceptionBinary = implode(DIRECTORY_SEPARATOR, [$vendorDir, 'vendor', 'bin', 'codecept']);

        if (!file_exists($codeceptionBinary))
        {
            return null;
        }

        return $codeceptionBinary;
    }

    protected function findVendorPath(string $dir, int $tries) : ?string
    {
        if ($tries <=0 || $dir === '/' || !is_dir($dir))
        {
            return null;
        }

        if (file_exists($dir . DIRECTORY_SEPARATOR . 'vendor'))
        {
            return $dir;
        }

        return $this->findVendorPath(dirname($dir), --$tries);
    }
}
