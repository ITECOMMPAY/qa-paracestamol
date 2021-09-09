<?php

namespace Paracetamol\Settings;

class SettingsParse implements ICodeceptionHelperSettings
{
    // Arguments
    protected string    $suite;
    protected string    $codeceptionConfigPath;
    protected string    $resultFile;

    // Options
    protected bool      $cacheTests   = false;
    protected array     $override     = [];
    protected array     $env          = [];
    protected string    $storeCacheIn = '';

    // Parameters from codeception.yml
    protected array     $codeceptionConfig;
    protected string    $testProjectPath;
    protected string    $namespace;
    protected string    $testsPath;
    protected string    $supportPath;
    protected string    $outputPath;

    // Computed values
    protected string    $codeceptionBinPath;

    public function setSuite(string $suite) : void
    {
        $this->suite = $suite;
    }

    public function setCodeceptionConfigPath(string $path) : void
    {
        $this->codeceptionConfigPath = $path;
    }

    public function setCodeceptionBinPath(string $path) : void
    {
        $this->codeceptionBinPath = $path;
    }

    public function setTestProjectPath(string $projectDir) : void
    {
        $this->testProjectPath = $projectDir;
    }

    public function setCodeceptionConfig(array $codeceptionConfig) : void
    {
        $this->codeceptionConfig = $codeceptionConfig;
    }

    public function setTestsPath(string $path) : void
    {
        $this->testsPath = $path;
    }

    public function setSupportPath(string $path) : void
    {
        $this->supportPath = $path;
    }

    public function setOutputPath(string $path) : void
    {
        $this->outputPath = $path;
    }

    public function setNamespace(string $namespace) : void
    {
        $this->namespace = $namespace;
    }

    public function setOverride(array $override) : void
    {
        $this->override = $override;
    }

    public function getCodeceptionConfigPath() : string
    {
        return $this->codeceptionConfigPath;
    }

    public function getCodeceptionBinPath() : string
    {
        return $this->codeceptionBinPath;
    }

    public function getSuite() : string
    {
        return $this->suite;
    }

    public function getTestProjectPath() : string
    {
        return $this->testProjectPath;
    }

    public function getCodeceptionConfig() : array
    {
        return $this->codeceptionConfig;
    }

    public function getNamespace() : string
    {
        return $this->namespace;
    }

    public function getTestsPath() : string
    {
        return $this->testsPath;
    }

    public function getSupportPath() : string
    {
        return $this->supportPath;
    }

    public function getOutputPath() : string
    {
        return $this->outputPath;
    }

    public function getOverride() : array
    {
        return $this->override;
    }

    public function getResultFile() : string
    {
        return $this->resultFile;
    }

    public function setResultFile(string $resultFile) : void
    {
        $this->resultFile = $resultFile;
    }

    public function isCacheTests() : bool
    {
        return $this->cacheTests;
    }

    public function setCacheTests(bool $cacheTests) : void
    {
        $this->cacheTests = $cacheTests;
    }

    public function getEnv() : array
    {
        return $this->env;
    }

    public function setEnv(array $env) : void
    {
        $this->env = $env;
    }

    public function getStoreCacheIn() : string
    {
        return $this->storeCacheIn;
    }

    public function setStoreCacheIn(string $storeCacheIn) : void
    {
        $this->storeCacheIn = $storeCacheIn;
    }
}
