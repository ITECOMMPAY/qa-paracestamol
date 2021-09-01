<?php


namespace Paracetamol\Test\CodeceptWrapper;


use Ds\Set;
use Paracetamol\Helpers\JsonLogParser\JsonLogParserFactory;
use Paracetamol\Log\Log;
use Paracetamol\Settings\SettingsRun;
use Paracetamol\Test\CodeceptWrapper\Wrapper\CestWrapper;
use Paracetamol\Test\CodeceptWrapper\Wrapper\ClusterCestWrapper;
use Paracetamol\Test\CodeceptWrapper\Wrapper\TestWrapper;
use Paracetamol\Test\RunnerFactory;

class CodeceptWrapperFactory
{
    protected Log                  $log;
    protected SettingsRun          $settings;
    protected JsonLogParserFactory $jsonLogParserFactory;
    protected RunnerFactory        $runnerFactory;

    public function __construct(Log $log, SettingsRun $settings, JsonLogParserFactory $jsonLogParserFactory, RunnerFactory $runnerFactory)
    {
        $this->log                  = $log;
        $this->settings             = $settings;
        $this->jsonLogParserFactory = $jsonLogParserFactory;
        $this->runnerFactory        = $runnerFactory;
    }

    public function getTestWrapper(string $cestName, string $methodName) : TestWrapper
    {
        return new TestWrapper($this->log, $this->settings, $this->jsonLogParserFactory, $cestName, $methodName);
    }

    public function getCestWrapper(string $cestName, Set $actualGroups, ?Set $expectedGroups = null) : CestWrapper
    {
        return new CestWrapper($this->log, $this->settings, $this->jsonLogParserFactory, $cestName, $actualGroups, $expectedGroups);
    }

    public function getClusterCestWrapper(string $cestName, Set $actualGroups, ?Set $expectedGroups = null) : ClusterCestWrapper
    {
        return new ClusterCestWrapper($this->log, $this, $this->runnerFactory, $cestName, $actualGroups, $expectedGroups);
    }
}
