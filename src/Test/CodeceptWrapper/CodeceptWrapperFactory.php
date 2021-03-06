<?php


namespace Paracestamol\Test\CodeceptWrapper;


use Ds\Set;
use Paracestamol\Helpers\XmlLogParser\LogParserFactory;
use Paracestamol\Log\Log;
use Paracestamol\Settings\SettingsRun;
use Paracestamol\Test\CodeceptWrapper\Wrapper\CestWrapper;
use Paracestamol\Test\CodeceptWrapper\Wrapper\ClusterCestWrapper;
use Paracestamol\Test\CodeceptWrapper\Wrapper\ClusterCestWrapper\BombletWrapperFactory;
use Paracestamol\Test\CodeceptWrapper\Wrapper\TestWrapper;
use Paracestamol\Test\Delayer;
use Paracestamol\Test\RunnerFactory;

class CodeceptWrapperFactory
{
    protected Log                   $log;
    protected SettingsRun           $settings;
    protected LogParserFactory      $logParserFactory;
    protected Delayer               $delayer;
    protected RunnerFactory         $runnerFactory;
    protected BombletWrapperFactory $bombletWrapperFactory;

    public function __construct(Log $log, SettingsRun $settings, LogParserFactory $logParserFactory, Delayer $delayer, RunnerFactory $runnerFactory, BombletWrapperFactory $bombletWrapperFactory)
    {
        $this->log                   = $log;
        $this->settings              = $settings;
        $this->logParserFactory      = $logParserFactory;
        $this->delayer               = $delayer;
        $this->runnerFactory         = $runnerFactory;
        $this->bombletWrapperFactory = $bombletWrapperFactory;
    }

    public function getTestWrapper(string $cestName, string $methodName, Set $actualGroups) : TestWrapper
    {
        return new TestWrapper($this->log, $this->settings, $this->logParserFactory, $cestName, $methodName, $actualGroups);
    }

    public function getCestWrapper(string $cestName, Set $actualGroups, ?Set $expectedGroups = null) : CestWrapper
    {
        return new CestWrapper($this->log, $this->settings, $this->logParserFactory, $this->delayer, $cestName, $actualGroups, $expectedGroups);
    }

    public function getClusterCestWrapper(string $cestName, Set $actualGroups, ?Set $expectedGroups = null) : ClusterCestWrapper
    {
        return new ClusterCestWrapper($this->log, $this->bombletWrapperFactory, $this->runnerFactory, $cestName, $actualGroups, $expectedGroups);
    }
}
