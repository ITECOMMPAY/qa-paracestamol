<?php


namespace Paracetamol\Test;


use Ds\Map;
use Ds\Queue;
use Paracetamol\Log\Log;
use Paracetamol\Settings\SettingsRun;
use Paracetamol\Test\CodeceptWrapper\ICodeceptWrapper;

class RunnersSupervisor
{
    protected Log         $log;
    protected SettingsRun $settings;
    protected Queue       $runners;

    protected int         $runnersTouched = 0;
    protected Queue       $failedTests;
    protected Map         $passedTestsDurations;

    public function __construct(Log $log, SettingsRun $settings, RunnerFactory $runnerFactory, array $queues)
    {
        $this->log = $log;
        $this->settings = $settings;

        $this->prepareRunners($runnerFactory, $queues);
        $this->failedTests = new Queue();
        $this->passedTestsDurations = new Map();
    }

    /**
     * @param RunnerFactory $runnerFactory
     * @param Queue[] $queues
     */
    protected function prepareRunners(RunnerFactory $runnerFactory, array $queues) : void
    {
        $this->log->veryVerbose('Preparing test runners for ' . count($queues) . ' process(es)');

        $this->runners = new Queue();

        foreach ($queues as $queue)
        {
            $this->runners->push($runnerFactory->get($queue));
        }
    }

    protected function wait()
    {
        $this->runnersTouched += 1;

        if ($this->runnersTouched < $this->runners->count())
        {
            return;
        }

        $this->runnersTouched = 0;
        usleep(4000); // max 250 RPS, but CPU usage should go down
    }

    public function run()
    {
        $this->log->verbose("Running tests");

        while (!$this->runners->isEmpty())
        {
            /** @var Runner $runner */
            $runner = $this->runners->pop();

            if ($runner->ticking())
            {
                $this->runners->push($runner);
            }
            else
            {
                $this->saveRunnerData($runner);
            }

            $this->outputFirstFailedTest($runner);
            $this->wait();
        }
    }

    protected function outputFirstFailedTest(Runner $runner) : void
    {
        if (!$this->settings->isShowFirstFail() || $runner->getFailedTests()->isEmpty())
        {
            return;
        }

        $this->settings->setShowFirstFail(false);

        /** @var ICodeceptWrapper $failedTest */
        $failedTest = $runner->getFailedTests()->peek();

        $error = trim($failedTest->getErrorOutput());

        if (empty($error))
        {
            $error = $failedTest->getOutput();

            $paddedError = preg_replace('%^%m', '           ', $error);
            $error = $paddedError ?? $error;
        }

        $this->log->verbose($error);
    }

    protected function saveRunnerData(Runner $runner) : void
    {
        /** @var ICodeceptWrapper $test */
        foreach ($runner->getFailedTests() as $test)
        {
            $this->failedTests->push($test);
        }

        /**
         * @var string $testName
         * @var int $duration
         */
        foreach ($runner->getPassedTestsDuration() as $testName => $duration)
        {
            $this->passedTestsDurations->put($testName, $duration);
        }
    }

    /**
     * @return Queue - [ICodeceptWrapper]
     */
    public function getFailedTests() : Queue
    {
        return $this->failedTests;
    }

    /**
     * @return Map - [testName => duration]
     */
    public function getPassedTestsDurations() : Map
    {
        return $this->passedTestsDurations;
    }
}
