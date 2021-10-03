<?php


namespace Paracetamol\Test;


use Ds\Map;
use Ds\Queue;
use Paracetamol\Helpers\TestNameParts;
use Paracetamol\Log\Log;
use Paracetamol\Settings\SettingsRun;
use Paracetamol\Test\CodeceptWrapper\ICodeceptWrapper;

class RunnersSupervisor
{
    protected Log           $log;
    protected SettingsRun   $settings;
    protected RunnerFactory $runnerFactory;

    protected Queue         $runners;
    protected int           $processCount;

    protected Queue         $failedTests;
    protected Queue         $failedTestsNoRerun;
    protected Queue         $timedOutTests;
    protected Queue         $markedSkippedTests;
    protected Map           $passedTestsDurations;
    protected Map           $failedTestsRerunCounts;

    protected TestNameParts $skipRerunsForTestNames;

    protected bool          $continuousRerun;

    public function __construct(Log $log, SettingsRun $settings, RunnerFactory $runnerFactory, array $queues, bool $continuousRerun)
    {
        $this->log = $log;
        $this->settings = $settings;
        $this->runnerFactory = $runnerFactory;

        $this->prepareRunners($runnerFactory, $queues);

        $this->failedTests          = new Queue();
        $this->failedTestsNoRerun   = new Queue();
        $this->timedOutTests        = new Queue();
        $this->markedSkippedTests   = new Queue();

        $this->passedTestsDurations   = new Map();
        $this->failedTestsRerunCounts = new Map();

        $this->continuousRerun = $continuousRerun;

        $this->skipRerunsForTestNames = new TestNameParts($this->settings->getSkipReruns());
    }

    /**
     * @param RunnerFactory $runnerFactory
     * @param Queue[] $queues
     */
    protected function prepareRunners(RunnerFactory $runnerFactory, array $queues) : void
    {
        $this->processCount = count($queues);

        $this->log->veryVerbose('Preparing test runners for ' . $this->processCount . ' process(es)');

        $this->runners = new Queue();

        foreach ($queues as $queue)
        {
            $this->runners->push($runnerFactory->get($queue));
        }
    }

    public function run()
    {
        $this->log->verbose("Running tests");

        while (!$this->runners->isEmpty())
        {
            $runnersCount = $this->runners->count();

            for ($i = 0; $i < $runnersCount; $i++)
            {
                $this->touchRunner();
            }

            usleep(10000); // max 100 RPS, but CPU usage should go down
        }
    }

    protected function touchRunner() : void
    {
        /** @var Runner $runner */
        $runner = $this->runners->pop();

        $this->outputFirstFailedTest($runner);

        if ($runner->ticking())
        {
            $this->runners->push($runner);
            $this->tryTakeSomeBurden($runner);
            return;
        }

        $this->saveFinishedRunnerData($runner);

        if (!$this->continuousRerun)
        {
            return;
        }

        $queue = $this->getTestsForRerun();

        if (!$queue->isEmpty())
        {
            $this->runners->push($this->runnerFactory->get($queue)->setLabel('(RERUN)'));
        }
    }

    protected function tryTakeSomeBurden(Runner $runner) : void
    {
        if (!$this->continuousRerun)
        {
            return;
        }

        if ($runner->hasEmptyQueue())
        {
            return;
        }

        if ($this->runners->count() >= $this->processCount)
        {
            return;
        }

        $test = $runner->popQueue();
        $queue = new Queue([$test]);
        $label = $runner->getLabel();

        $this->runners->push($this->runnerFactory->get($queue)->setLabel($label));
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

    protected function saveFinishedRunnerData(Runner $runner) : void
    {
        /** @var ICodeceptWrapper $test */
        foreach ($runner->getFailedTests() as $test)
        {
            if ($this->rerunIsForbidden($test))
            {
                $this->failedTestsNoRerun->push($test);
            }
            else
            {
                $this->failedTests->push($test);
            }
        }

        /** @var ICodeceptWrapper $test */
        foreach ($runner->getTimedOutTests() as $test)
        {
            $this->timedOutTests->push($test);
        }

        /** @var ICodeceptWrapper $test */
        foreach ($runner->getMarkedSkippedTests() as $test)
        {
            $this->markedSkippedTests->push($test);
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

    protected function rerunIsForbidden(ICodeceptWrapper $test) : bool
    {
        if ($this->skipRerunsForTestNames->isEmpty())
        {
            return false;
        }

        return $test->matches($this->skipRerunsForTestNames);
    }

    protected function getTestsForRerun() : Queue
    {
        $result = new Queue();

        if ($this->failedTests->isEmpty())
        {
            return $result;
        }

        if ($this->settings->getRerunCount() === 0)
        {
            return $result;
        }

        foreach ($this->failedTests as $test)
        {
            if ($this->rerunCountIsExhausted($test))
            {
                $this->failedTestsNoRerun->push($test);
                continue;
            }

            $result->push($test);
        }

        return $result;
    }

    protected function rerunCountIsExhausted(ICodeceptWrapper $test) : bool
    {
        if (!$this->failedTestsRerunCounts->hasKey($test))
        {
            $this->failedTestsRerunCounts->put($test, -1);
        }

        $rerunCount = $this->failedTestsRerunCounts->get($test);

        $this->failedTestsRerunCounts->put($test, ++$rerunCount);

        return $rerunCount === $this->settings->getRerunCount();
    }

    /**
     * @return Queue - [ICodeceptWrapper]
     */
    public function getFailedTests() : Queue
    {
        return $this->failedTests;
    }

    /**
     * @return Queue - [ICodeceptWrapper]
     */
    public function getFailedTestsNoRerun() : Queue
    {
        return $this->failedTestsNoRerun;
    }

    /**
     * @return Queue - [ICodeceptWrapper]
     */
    public function getTimedOutTests() : Queue
    {
        return $this->timedOutTests;
    }

    /**
     * @return Queue - [ICodeceptWrapper]
     */
    public function getMarkedSkippedTests() : Queue
    {
        return $this->markedSkippedTests;
    }

    /**
     * @return Map - [testName => duration]
     */
    public function getPassedTestsDurations() : Map
    {
        return $this->passedTestsDurations;
    }
}
