<?php


namespace Paracestamol\Test;


use Ds\Queue;
use Paracestamol\Log\Log;
use Paracestamol\Settings\SettingsRun;

class RunnersSupervisorFactory
{
    protected Log           $log;
    protected SettingsRun   $settings;
    protected RunnerFactory $runnerFactory;

    public function __construct(Log $log, SettingsRun $settings, RunnerFactory $runnerFactory)
    {
        $this->log = $log;
        $this->settings = $settings;
        $this->runnerFactory = $runnerFactory;
    }

    /**
     * @param Queue[] $queues
     * @return RunnersSupervisor
     */
    public function get(array $queues, bool $continuousRerun = false) : RunnersSupervisor
    {
        return new RunnersSupervisor($this->log, $this->settings, $this->runnerFactory, $queues, $continuousRerun);
    }
}
