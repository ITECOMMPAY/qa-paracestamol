<?php


namespace Paracestamol\Test;


use Ds\Map;
use Ds\Queue;
use Paracestamol\Helpers\TestNameParts;
use Paracestamol\Log\Log;
use Paracestamol\Settings\SettingsRun;
use Paracestamol\Test\CodeceptWrapper\ICodeceptWrapper;
use Paracestamol\Test\CodeceptWrapper\Wrapper\CestWrapper;
use Paracestamol\Test\CodeceptWrapper\Wrapper\ClusterCestWrapper;
use Paracestamol\Test\CodeceptWrapper\Wrapper\ClusterCestWrapper\IClusterBomblet;

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

    protected ?Runner       $mostBurdenedRunner = null;

    protected Queue         $explodedClusterCests;

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

        $this->explodedClusterCests = new Queue();

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

            $this->tryTakeSomeBurden();

            usleep($this->settings->getTickFrequencyUs());
        }

        $this->implodeClusterCests();
    }

    protected function touchRunner() : void
    {
        /** @var Runner $runner */
        $runner = $this->runners->pop();

        $this->outputFirstFailedTest($runner);

        if ($runner->ticking())
        {
            $this->runners->push($runner);
            $this->findMostBurdenedRunner($runner);
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

    protected function findMostBurdenedRunner(Runner $runner) : void
    {
        if (!$this->continuousRerun)
        {
            return;
        }

        if (!$runner->hasTestRunning() || $runner->hasEmptyQueue())
        {
            return;
        }

        if ($this->mostBurdenedRunner === null)
        {
            $this->mostBurdenedRunner = $runner;
            return;
        }

        if ($this->mostBurdenedRunner->testsCount() < $runner->testsCount())
        {
            $this->mostBurdenedRunner = $runner;
        }
    }

    /**
     * If runner has many tests in its queue and there is a free process - move some tests to a new runner
     *
     * @param Runner $runner
     */
    protected function tryTakeSomeBurden() : void
    {
        if (!$this->continuousRerun)
        {
            return;
        }

        if ($this->mostBurdenedRunner === null || !$this->mostBurdenedRunner->hasTestRunning() || $this->mostBurdenedRunner->hasEmptyQueue())
        {
            return;
        }

        if ($this->runners->count() >= $this->processCount)
        {
            return;
        }

        $testsToTake = (int) ceil($this->mostBurdenedRunner->testsCount() / 2);
        $queue = new Queue();

        for ($i = 0; $i < $testsToTake; $i++)
        {
            $test = $this->mostBurdenedRunner->popQueue();

            $queue->push($test);
        }

        $label = $this->mostBurdenedRunner->getLabel();

        $this->log->debug("$testsToTake tests moved to new runner, because there is a free process");

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

            if (($test instanceof CestWrapper || $test instanceof ClusterCestWrapper) && $this->settings->isFastCestRerun())
            {
                if (!$test->hasPassedTestsThisRun())
                {
                    $this->log->debug($test . ' was excluded from the next rerun because it doesn\'t have any tests passed at the current run');

                    $this->failedTestsNoRerun->push($test);
                    continue;
                }

                if ($test instanceof ClusterCestWrapper && $test->isExplodable())
                {
                    foreach ($this->explodeClusterCest($test) as $testFromCest)
                    {
                        $result->push($testFromCest);
                    }

                    continue;
                }
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

        return $rerunCount >= $this->settings->getRerunCount();
    }

    protected function explodeClusterCest(ClusterCestWrapper $cest) : Queue
    {
        $this->log->debug($cest . ' was divided into separate tests that will be run in parallel');

        $result = new Queue();

        $cestCurrentRerunCount = $this->failedTestsRerunCounts->get($cest);

        foreach ($cest->explode() as $failedTest)
        {
            $this->failedTestsRerunCounts->put($failedTest, $cestCurrentRerunCount);

            $result->push($failedTest);
        }

        $this->explodedClusterCests->push($cest);

        return $result;
    }

    protected function implodeClusterCests() : void
    {
        if (!$this->continuousRerun || $this->explodedClusterCests->isEmpty())
        {
            return;
        }

        /** @var ClusterCestWrapper $clusterCest */
        foreach ($this->explodedClusterCests as $clusterCest)
        {
            $clusterCest->implode();

            if ($clusterCest->isSuccessful())
            {
                $this->log->progressAdvance();
                $this->passedTestsDurations->put((string) $clusterCest, $clusterCest->getActualDuration());
                continue;
            }

            $this->failedTestsNoRerun->push($clusterCest);
        }

        $filteredFailedTests = new Queue();

        foreach ($this->failedTestsNoRerun as $failedTest)
        {
            if ($failedTest instanceof IClusterBomblet)
            {
                continue;
            }

            $filteredFailedTests->push($failedTest);
        }

        $this->failedTestsNoRerun = $filteredFailedTests;
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
