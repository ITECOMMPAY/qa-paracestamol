<?php


namespace Paracetamol\Settings;

use Ds\Set;

class SettingsRun implements ICodeceptionHelperSettings
{
    // Arguments
    protected string    $suite;
    protected string    $codeceptionConfigPath;
    protected int       $processCount;

    // Options
    protected string    $projectName             =  '';
    protected int       $rerunCount              =   0;
    protected bool      $continuousRerun         =  false;
    protected array     $env                     =  [];
    protected array     $override                =  [];
    protected int       $delayMsec               =  -1;
    protected int       $maxRps                  =  10;
    protected bool      $showFirstFail           =  false;
    protected bool      $cacheTests              =  false;
    protected string    $storeCacheIn            =  '';
    protected string    $statEndpoint            =  '';
    protected int       $idleTimeoutSec          =  -1;
    protected array     $onlyTests               =  [];
    protected array     $skipTests               =  [];
    protected array     $skipReruns              =  [];
    protected array     $runBeforeSeries         =  [];
    protected array     $runBeforeParallel       =  [];
    protected array     $runAfterSeries          =  [];
    protected array     $runAfterParallel        =  [];
    protected bool      $rerun_whole_series      =  false;
    protected bool      $serial_before_fails_run =  false;
    protected array     $notDividableRerunWhole  =  [];
    protected array     $notDividableRerunFailed =  [];
    protected int       $bulkRowsCount           = 500;
    protected bool      $noMemoryLimit           =  false;

    // Parameters from codeception.yml
    protected array     $codeceptionConfig;
    protected string    $testProjectPath;
    protected string    $namespace;
    protected string    $testsPath;
    protected string    $supportPath;
    protected string    $outputPath;

    // Computed values
    protected Set       $groups;
    protected \DateTime $runStart;
    protected string    $runId;
    protected string    $codeceptionBinPath;
    protected ?string   $envAsString = null;
    protected ?string   $overrideAsString = null;
    protected bool      $adaptiveDelay = true;
    protected bool      $successfullyFetchedDurations = false;
    protected int       $maxRunDuration = 0;
    protected string    $runOutputPath = '';
    protected float     $delaySeconds = -1;
    protected int       $minTestDurationSec    = 0;
    protected int       $maxTestDurationSec    = 0;
    protected int       $medianTestDurationSec = 0;

    public function getEnvAsString() : string
    {
        if (!isset($this->envAsString))
        {
            $this->envAsString = implode(',', $this->env);
        }

        return $this->envAsString;
    }

    public function getOverrideAsString() : string
    {
        if (!isset($this->overrideAsString))
        {
            $this->overrideAsString = implode(',', $this->override);
        }

        return $this->overrideAsString;
    }

    public function setGroups(array $groups) : void
    {
        $this->groups = new Set($groups);
    }

    public function setDelayMsec(int $milliseconds) : void
    {
        $this->delayMsec = $milliseconds;
        $this->delaySeconds = $milliseconds / 1000;
    }

    //=================================================

    public function setSuite(string $suite) : void
    {
        $this->suite = $suite;
    }

    public function setCodeceptionConfigPath(string $path) : void
    {
        $this->codeceptionConfigPath = $path;
    }

    public function setProcessCount(int $count) : void
    {
        $this->processCount = $count;
    }

    public function setRerunCount(int $count) : void
    {
        $this->rerunCount = $count;
    }

    public function setAdaptiveDelay(bool $value) : void
    {
        $this->adaptiveDelay = $value;
    }

    public function setIdleTimeoutSec(int $idleTimeoutSec) : void
    {
        $this->idleTimeoutSec = $idleTimeoutSec;
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

    public function setRunStart(\DateTime $dateTime) : void
    {
        $this->runStart = $dateTime;
    }

    public function setRunId(string $runId) : void
    {
        $this->runId = $runId;
    }

    public function setStatEndpoint(string $endpoint) : void
    {
        $this->statEndpoint = $endpoint;
    }

    public function setShowFirstFail(bool $param) : void
    {
        $this->showFirstFail = $param;
    }

    public function setProjectName(string $projectName) : void
    {
        $this->projectName = $projectName;
    }

    public function setMaxRunDuration(int $maxRunDuration) : void
    {
        $this->maxRunDuration = $maxRunDuration;
    }

    public function setOverride(array $override) : void
    {
        $this->override = $override;
    }

    public function setRunBeforeSeries(array $runBeforeSeries) : void
    {
        $this->runBeforeSeries = $runBeforeSeries;
    }

    public function setRunBeforeParallel(array $runBeforeParallel) : void
    {
        $this->runBeforeParallel = $runBeforeParallel;
    }

    public function setRunAfterSeries(array $runAfterSeries) : void
    {
        $this->runAfterSeries = $runAfterSeries;
    }

    public function setRunAfterParallel(array $runAfterParallel) : void
    {
        $this->runAfterParallel = $runAfterParallel;
    }

    public function setSkipTests(array $skipTests) : void
    {
        $this->skipTests = $skipTests;
    }

    public function setSuccessfullyFetchedDurations(bool $value) : void
    {
        $this->successfullyFetchedDurations = $value;
    }

    public function setMaxRps(int $maxRps) : void
    {
        $this->maxRps = $maxRps;
    }

    public function setMinTestDurationSec(int $minTestDurationSec) : void
    {
        $this->minTestDurationSec = $minTestDurationSec;
    }

    public function setMaxTestDurationSec(int $maxTestDurationSec) : void
    {
        $this->maxTestDurationSec = $maxTestDurationSec;
    }

    public function setMedianTestDurationSec(int $medianTestDurationSec) : void
    {
        $this->medianTestDurationSec = $medianTestDurationSec;
    }

    public function setBulkRowsCount(int $bulkRowsCount) : void
    {
        $this->bulkRowsCount = $bulkRowsCount;
    }

    public function setSkipReruns(array $skipReruns) : void
    {
        $this->skipReruns = $skipReruns;
    }

    public function setOnlyTests(array $onlyTests) : void
    {
        $this->onlyTests = $onlyTests;
    }

    public function setNotDividableRerunWhole(array $notDividableRerunWhole) : void
    {
        $this->notDividableRerunWhole = $notDividableRerunWhole;
    }

    public function setNotDividableRerunFailed(array $notDividableRerunFailed) : void
    {
        $this->notDividableRerunFailed = $notDividableRerunFailed;
    }

    public function setRunOutputPath(string $runOutputPath) : void
    {
        $this->runOutputPath = $runOutputPath;
    }

    public function setCacheTests(bool $cacheTests) : void
    {
        $this->cacheTests = $cacheTests;
    }

    public function setEnv(array $env) : void
    {
        $this->env = $env;
    }

    public function setStoreCacheIn(string $storeCacheIn) : void
    {
        $this->storeCacheIn = $storeCacheIn;
    }

    public function setRerunWholeSeries(bool $rerun_whole_series) : void
    {
        $this->rerun_whole_series = $rerun_whole_series;
    }

    public function setSerialBeforeFailsRun(bool $serial_before_fails_run) : void
    {
        $this->serial_before_fails_run = $serial_before_fails_run;
    }

    public function setContinuousRerun(bool $continuousRerun) : void
    {
        $this->continuousRerun = $continuousRerun;
    }

    public function setNoMemoryLimit(bool $noMemoryLimit) : void
    {
        $this->noMemoryLimit = $noMemoryLimit;
    }






































    public function getRunStart() : \DateTime
    {
        return $this->runStart;
    }

    public function getRunId() : string
    {
        return $this->runId;
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

    public function getProcessCount() : int
    {
        return $this->processCount;
    }

    public function getRerunCount() : int
    {
        return $this->rerunCount;
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

    public function getGroups() : Set
    {
        return $this->groups;
    }

    public function getDelayMsec() : int
    {
        return $this->delayMsec;
    }

    public function getDelaySeconds() : float
    {
        return $this->delaySeconds;
    }

    public function getEnv() : array
    {
        return $this->env;
    }

    public function getStatEndpoint() : string
    {
        return $this->statEndpoint;
    }

    public function isShowFirstFail() : bool
    {
        return $this->showFirstFail;
    }

    public function getProjectName() : string
    {
        return $this->projectName;
    }

    public function getMaxRunDuration() : int
    {
        return $this->maxRunDuration;
    }

    public function isAdaptiveDelay() : bool
    {
        return $this->adaptiveDelay;
    }

    public function getIdleTimeoutSec() : int
    {
        return $this->idleTimeoutSec;
    }

    public function getOverride() : array
    {
        return $this->override;
    }

    public function getRunBeforeSeries() : array
    {
        return $this->runBeforeSeries;
    }

    public function getRunBeforeParallel() : array
    {
        return $this->runBeforeParallel;
    }

    public function getRunAfterSeries() : array
    {
        return $this->runAfterSeries;
    }

    public function getRunAfterParallel() : array
    {
        return $this->runAfterParallel;
    }

    public function getSkipTests() : array
    {
        return $this->skipTests;
    }

    public function isSuccessfullyFetchedDurations() : bool
    {
        return $this->successfullyFetchedDurations;
    }

    public function getMaxRps() : int
    {
        return $this->maxRps;
    }

    public function getMinTestDurationSec() : int
    {
        return $this->minTestDurationSec;
    }

    public function getMaxTestDurationSec() : int
    {
        return $this->maxTestDurationSec;
    }

    public function getMedianTestDurationSec() : int
    {
        return $this->medianTestDurationSec;
    }

    public function getBulkRowsCount() : int
    {
        return $this->bulkRowsCount;
    }

    public function getSkipReruns() : array
    {
        return $this->skipReruns;
    }

    public function getOnlyTests() : array
    {
        return $this->onlyTests;
    }

    public function getNotDividableRerunWhole() : array
    {
        return $this->notDividableRerunWhole;
    }

    public function getNotDividableRerunFailed() : array
    {
        return $this->notDividableRerunFailed;
    }

    public function getRunOutputPath() : string
    {
        return $this->runOutputPath;
    }

    public function isCacheTests() : bool
    {
        return $this->cacheTests;
    }

    public function getStoreCacheIn() : string
    {
        return $this->storeCacheIn;
    }

    public function isRerunWholeSeries() : bool
    {
        return $this->rerun_whole_series;
    }

    public function isSerialBeforeFailsRun() : bool
    {
        return $this->serial_before_fails_run;
    }

    public function isContinuousRerun() : bool
    {
        return $this->continuousRerun;
    }

    public function isNoMemoryLimit() : bool
    {
        return $this->noMemoryLimit;
    }
}
