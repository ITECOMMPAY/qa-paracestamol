<?php

namespace Paracetamol\Helpers;

use Codeception\Util\PathResolver;
use Paracetamol\Exceptions\GeneralException;
use Paracetamol\Exceptions\InvalidArgumentException;
use Paracetamol\Log\Log;
use Paracetamol\Settings\ICodeceptionHelperSettings;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

trait CodeceptionProjectParser
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
        $this->setCodeceptionConfig($input);

        $this->setEnvNames($input);

        $this->setSuiteConfig();

        $this->setNamespace();

        $this->setTestsPath();

        $this->setSupportPath();

        $this->setOutputPath();

        $this->setEnabledModules();
    }

    private function setTestProjectPath() : void
    {
        $projectDir = realpath(dirname($this->getSettings()->getCodeceptionConfigPath()));

        $this->getLog()->debug('Project dir is: ' . $projectDir);

        $this->getSettings()->setTestProjectPath($projectDir);
    }

    private function setCodeceptionConfig(InputInterface $input) : void
    {
        $codeceptionConfig = CodeceptionConfig::config($this->getSettings()->getCodeceptionConfigPath());

        $override = !empty($input->getOption('override')) ? $input->getOption('override') : $this->getSettings()->getOverride();

        $codeceptionConfig = $this->overrideCodeceptionConfigFromOptions($codeceptionConfig, $override);

        $this->getSettings()->setOverride($this->getOverrideWithoutOutputPath($override));

        $this->getSettings()->setCodeceptionConfig($codeceptionConfig);
    }

    private function setEnvNames(InputInterface $input) : void
    {
        $envNames = $this->resolveEnvNames($input);

        $this->getSettings()->setEnv($envNames);
    }

    private function resolveEnvNames(InputInterface $input) : array
    {
        $envOptions = !empty($input->getOption('env')) ? $input->getOption('env') : $this->getSettings()->getEnv();

        if (empty($envOptions))
        {
            return [];
        }

        sort($envOptions, SORT_NATURAL);

        $envNames = [];

        foreach ($envOptions as $envOption)
        {
            $envNames []= explode(',', $envOption);
        }

        $envNames = array_merge(...$envNames);

        return $envNames;
    }

    private function setSuiteConfig() : void
    {
        $suiteConfig = CodeceptionConfig::suiteSettings($this->settings->getSuite(), $this->settings->getCodeceptionConfig());

        $suiteConfig = $this->overrideSuiteConfigFromEnv($suiteConfig, $this->settings->getEnv());

        $this->getSettings()->setSuiteConfig($suiteConfig);
    }

    private function setNamespace() : void
    {
        $this->getSettings()->setNamespace($this->getSettings()->getSuiteConfig()['namespace'] ?? '');
    }

    private function setTestsPath() : void
    {
        $testsDir = $this->getSettings()->getSuiteConfig()['path'];

        $testsDir = realpath($testsDir);

        $this->getLog()->debug('Tests dir: ' . $testsDir);

        $this->getSettings()->setTestsPath($testsDir);
    }

    private function setSupportPath() : void
    {
        $supportSubdir = $this->getSettings()->getCodeceptionConfig()['paths']['support'];

        if (!PathResolver::isPathAbsolute($supportSubdir))
        {
            $supportSubdir = realpath($this->getSettings()->getTestProjectPath() . DIRECTORY_SEPARATOR . $supportSubdir);
        }

        $this->getLog()->debug('Support dir: ' . $supportSubdir);

        $this->getSettings()->setSupportPath($supportSubdir);
    }

    private function setOutputPath() : void
    {
        $outputSubdir = $this->getSettings()->getCodeceptionConfig()['paths']['output'];

        if (!PathResolver::isPathAbsolute($outputSubdir))
        {
            $outputSubdir = $this->getSettings()->getTestProjectPath() . DIRECTORY_SEPARATOR . $outputSubdir;
        }

        // Some test projects doesn't have an output dir created
        if (!is_dir($outputSubdir) && !mkdir($outputSubdir, 0777, true) && !is_dir($outputSubdir))
        {
            throw new GeneralException(sprintf('Directory "%s" was not created', $outputSubdir));
        }

        $outputSubdir = realpath($outputSubdir);

        if (!is_writable($outputSubdir)) {
            @chmod($outputSubdir, 0777);
        }

        if (!is_writable($outputSubdir)) {
            throw new GeneralException(
                "Path for output is not writable. Please, set appropriate access mode for output path: {$outputSubdir}"
            );
        }

        $this->getLog()->debug('Output dir: ' . $outputSubdir);

        $this->getSettings()->setOutputPath($outputSubdir);
    }

    private function setEnabledModules() : void
    {
        $suiteConfig = $this->getSettings()->getSuiteConfig();
        $this->getSettings()->setEnabledModules(CodeceptionConfig::modules($suiteConfig) ?? []);
    }

    private function overrideCodeceptionConfigFromOptions(array $codeceptionConfig, array $override) : array
    {
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

        return CodeceptionConfig::mergeConfigs($codeceptionConfig, $configPatch);
    }

    private function overrideSuiteConfigFromEnv(array $suiteConfig, array $envNames) : array
    {
        foreach ($envNames as $envName)
        {
            if (!isset($suiteConfig['env'][$envName]))
            {
                $this->getLog()->note("Env: $envName - is not found");
                continue;
            }

            $suiteConfig = CodeceptionConfig::mergeConfigs($suiteConfig, $suiteConfig['env'][$envName]);
        }

        return $suiteConfig;
    }

    private function getOverrideWithoutOutputPath(array $override) : array
    {
        $result = [];

        foreach ($override as $option)
        {
            if (preg_match('%paths:\s*output:%mi', $option) === 1)
            {
                continue;
            }

            $result []= $option;
        }

        return $result;
    }

    private function findConfigPath(InputInterface $input) : ?string
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

    private function findCodeceptionBinPath() : ?string
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

        $this->getLog()->debug('Codeception bin: ' . $codeceptionBinary);

        return $codeceptionBinary;
    }

    private function findVendorPath(string $dir, int $tries) : ?string
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
            $this->getLog()->debug('Vendor dir: ' . $result);
            return $result;
        }

        return $this->findVendorPath(dirname($dir), --$tries);
    }
}
