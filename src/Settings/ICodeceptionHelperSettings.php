<?php

namespace Paracetamol\Settings;

interface ICodeceptionHelperSettings
{
    public function setCodeceptionBinPath(string $path) : void;

    public function getCodeceptionBinPath() : string;

    public function setCodeceptionConfigPath(string $path) : void;

    public function getCodeceptionConfigPath() : string;

    public function setCodeceptionConfig(array $codeceptionConfig) : void;

    public function getCodeceptionConfig() : array;

    public function setSuiteConfig(array $suiteConfig) : void;

    public function getSuiteConfig() : array;

    public function setOverride(array $override) : void;

    public function getOverride() : array;

    public function setSuite(string $suite) : void;

    public function getSuite() : string;

    public function setTestProjectPath(string $projectDir) : void;

    public function getTestProjectPath() : string;

    public function setTestsPath(string $path) : void;

    public function getTestsPath() : string;

    public function setSupportPath(string $path) : void;

    public function getSupportPath() : string;

    public function setOutputPath(string $path) : void;

    public function getOutputPath() : string;

    public function setNamespace(string $namespace) : void;

    public function getNamespace() : string;

    public function getEnabledModules() : array;

    public function setEnabledModules(array $enabledModules) : void;
}
