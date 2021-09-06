<?php

namespace Paracetamol\Helpers;

use Codeception\Configuration;
use Codeception\Util\PathResolver;
use Paracetamol\Exceptions\InvalidArgumentException;
use Paracetamol\Exceptions\UsageException;
use Paracetamol\Log\Log;
use Paracetamol\Settings\ICodeceptionHelperSettings;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

trait CodeceptionSettingsParser
{
    abstract protected function getLog() : Log;

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

    protected function resolveCodeceptionConfigPath(InputInterface $input) : void
    {
        $configPath = $this->findConfigPath($input);

        if ($configPath === null)
        {
            throw new InvalidArgumentException('The given path does not contain a codeception.yml file or the file is not accessible');
        }

        $this->getSettings()->setCodeceptionConfigPath($configPath);

        $this->setTestProjectPath();
    }

    protected function loadCodeceptionConfig(InputInterface $input) : void
    {
        $parsedConfig = Yaml::parseFile($this->getSettings()->getCodeceptionConfigPath());

        $this->getSettings()->setCodeceptionConfig($parsedConfig);

        $this->setNamespace();

        $this->overrideCodeceptionConfigFromOptions($input);

        $this->overrideCodeceptionConfigFromEnv($input);

        $this->setTestsPath();

        $this->setSupportPath();

        $this->setOutputPath();
    }

    protected function setTestProjectPath() : void
    {
        $projectDir = realpath(dirname($this->getSettings()->getCodeceptionConfigPath()));

        $this->getLog()->debug('Project dir is: ' . $projectDir);

        $this->getSettings()->setTestProjectPath($projectDir);
    }

    protected function setTestsPath() : void
    {
        $testsSubdir = $this->getSettings()->getCodeceptionConfig()['paths']['tests'];

        if ($testsSubdir === '.')
        {
            $testsSubdir = $this->getSettings()->getSuite();
        }

        $testsDir = realpath($this->getSettings()->getTestProjectPath() . DIRECTORY_SEPARATOR . $testsSubdir);

        $this->getLog()->debug('Tests dir is: ' . $testsDir);

        $this->getSettings()->setTestsPath($testsDir);
    }

    protected function setSupportPath() : void
    {
        $supportSubdir = $this->getSettings()->getCodeceptionConfig()['paths']['support'];

        if (!PathResolver::isPathAbsolute($supportSubdir))
        {
            $supportSubdir = realpath($this->getSettings()->getTestProjectPath() . DIRECTORY_SEPARATOR . $supportSubdir);
        }

        $this->getLog()->debug('Support dir is: ' . $supportSubdir);

        $this->getSettings()->setSupportPath($supportSubdir);
    }

    protected function setOutputPath() : void
    {
        $outputSubdir = $this->getSettings()->getCodeceptionConfig()['paths']['output'];

        if (!PathResolver::isPathAbsolute($outputSubdir))
        {
            $outputSubdir = $this->getSettings()->getTestProjectPath() . DIRECTORY_SEPARATOR . $outputSubdir;
        }

        // Some test projects doesn't have an output dir created
        if (!is_dir($outputSubdir) && !mkdir($outputSubdir, 0777, true) && !is_dir($outputSubdir))
        {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $outputSubdir));
        }

        $outputSubdir = realpath($outputSubdir);

        $this->getLog()->debug('Output dir is: ' . $outputSubdir);

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

        sort($envOptions, SORT_NATURAL);

        $envNames = [];

        foreach ($envOptions as $envOption)
        {
            $envNames []= explode(',', $envOption);
        }

        $envNames = array_merge(...$envNames);

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
        $this->getLog()->debug('Searching for codeception.yml');

        $config = $input->getArgument('config') . DIRECTORY_SEPARATOR . 'codeception.yml';

        if (!file_exists($config))
        {
            $this->getLog()->debug($config . ' - is not exist. Looking in another dir.');

            $config = getcwd() . DIRECTORY_SEPARATOR . $config;

            if (!file_exists($config))
            {
                $this->getLog()->debug($config . ' - is not exist. codeception.yml is not found');

                return null;
            }
        }

        $config = realpath($config);

        if ($config === false)
        {
            $this->getLog()->debug('Can\'t get realpath of codeception.yml');

            return null;
        }

        $this->getLog()->debug('codeception.yml is found: ' . $config);

        return $config;
    }

    protected function findCodeceptionBinPath() : ?string
    {
        $this->getLog()->debug('Searching for Codeception bin');

        $vendorDir = $this->findVendorPath(__DIR__, 10);

        if ($vendorDir === null)
        {
            return null;
        }

        $codeceptionBinary = implode(DIRECTORY_SEPARATOR, [$vendorDir, 'bin', 'codecept']);

        if (!file_exists($codeceptionBinary))
        {
            $this->getLog()->debug('Codeception bin is not found');
            return null;
        }

        $this->getLog()->debug('Codeception bin is: ' . $codeceptionBinary);

        return $codeceptionBinary;
    }

    protected function findVendorPath(string $dir, int $tries) : ?string
    {
        $this->getLog()->debug('Searching for vendor dir');

        if ($tries <=0 || $dir === '/' || !is_dir($dir))
        {
            $this->getLog()->debug('Vendor dir is not found');
            return null;
        }

        $result = $dir . DIRECTORY_SEPARATOR . 'vendor';

        if (file_exists($result))
        {
            $this->getLog()->debug('Vendor dir is: ' . $result);
            return $result;
        }

        return $this->findVendorPath(dirname($dir), --$tries);
    }
}
