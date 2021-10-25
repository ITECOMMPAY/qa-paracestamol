<?php


namespace Paracestamol\Test\CodeceptWrapper;


use Ds\Set;
use Paracestamol\Helpers\JsonLogParser\JsonLogParserFactory;
use Paracestamol\Log\Log;
use Paracestamol\Settings\SettingsRun;
use Paracestamol\Test\CodeceptWrapper\Wrapper\CestWrapper;
use Paracestamol\Test\CodeceptWrapper\Wrapper\ClusterCestWrapper;
use Paracestamol\Test\CodeceptWrapper\Wrapper\TestWrapper;
use Paracestamol\Test\Delayer;
use Paracestamol\Test\RunnerFactory;

class CodeceptWrapperFactory
{
    protected Log                  $log;
    protected SettingsRun          $settings;
    protected JsonLogParserFactory $jsonLogParserFactory;
    protected Delayer              $delayer;
    protected RunnerFactory        $runnerFactory;

    public function __construct(Log $log, SettingsRun $settings, JsonLogParserFactory $jsonLogParserFactory, Delayer $delayer, RunnerFactory $runnerFactory)
    {
        $this->log                  = $log;
        $this->settings             = $settings;
        $this->jsonLogParserFactory = $jsonLogParserFactory;
        $this->delayer              = $delayer;
        $this->runnerFactory        = $runnerFactory;
    }

    public function getTestWrapper(string $cestName, string $methodName) : TestWrapper
    {
        return new TestWrapper($this->log, $this->settings, $this->jsonLogParserFactory, $cestName, $methodName);
    }

    public function getCestWrapper(string $cestName, Set $actualGroups, ?Set $expectedGroups = null) : CestWrapper
    {
        return new CestWrapper($this->log, $this->settings, $this->jsonLogParserFactory, $this->delayer, $cestName, $actualGroups, $expectedGroups);
    }

    public function getClusterCestWrapper(string $cestName, Set $actualGroups, ?Set $expectedGroups = null) : ClusterCestWrapper
    {
        return new ClusterCestWrapper($this->log, $this, $this->runnerFactory, $cestName, $actualGroups, $expectedGroups);
    }
}
