<?php

namespace Paracetamol\Test\CodeceptWrapper\Wrapper;

use Ds\Queue;
use Ds\Set;
use Paracetamol\Helpers\JsonLogParser\Records\TestRecord;
use Paracetamol\Helpers\TestNameParts;
use Paracetamol\Log\Log;
use Paracetamol\Test\CodeceptWrapper\CodeceptWrapperFactory;
use Paracetamol\Test\CodeceptWrapper\ICodeceptWrapper;
use Paracetamol\Test\Runner;
use Paracetamol\Test\RunnerFactory;

/**
 * Releases a number of projectile tests on rerun
 */
class ClusterCestWrapper implements ICodeceptWrapper
{
    protected Log                    $log;
    protected CodeceptWrapperFactory $wrapperFactory;
    protected RunnerFactory          $runnerFactory;

    protected string                 $cestName;

    protected CestWrapper            $cest;
    protected Queue                  $failedTests;
    protected ?Runner                $runner = null;

    protected string                 $output = '';
    protected string                 $errorOutput = '';
    protected string                 $statusDescription = '';
    protected float                  $expectedDuration = 0.0;
    protected float                  $actualDuration = 0.0;

    public function __construct(Log $log, CodeceptWrapperFactory $wrapperFactory, RunnerFactory $runnerFactory, string $cestName, Set $actualGroups, ?Set $expectedGroups = null)
    {
        $this->log = $log;
        $this->wrapperFactory = $wrapperFactory;
        $this->runnerFactory = $runnerFactory;

        $this->cestName = $cestName;

        $this->cest = $wrapperFactory->getCestWrapper($cestName, $actualGroups, $expectedGroups);
        $this->failedTests = new Queue();
    }

    protected function reset() : void
    {
        $this->output = '';
        $this->errorOutput = '';
        $this->statusDescription = '';
        $this->runner = null;
    }

    public function start() : void
    {
        $this->reset();

        if ($this->failedTests->isEmpty())
        {
            $this->cest->start();
            return;
        }

        $this->log->progressHalt();
        $this->runner = $this->runnerFactory->get($this->failedTests);
    }

    protected function isFirstRun() : bool
    {
        return $this->runner === null;
    }

    public function isRunning() : bool
    {
        if ($this->isFirstRun())
        {
            $result = $this->cest->isRunning();

            if (!$result)
            {
                $this->updateActualDuration();
                $this->updateExpectedDuration();
                $this->parseFailedTestRecords();
            }

            return $result;
        }

        if ($this->runner->ticking())
        {
            return true;
        }

        $this->failedTests = $this->collectStrings($this->runner->getFailedTests());
        $this->updateActualDuration();
        $this->updateExpectedDuration();
        $this->log->progressContinue();
        return false;
    }

    protected function collectStrings(Queue $failedTests) : Queue
    {
        $processedTests = new Queue();
        $outputs = [];
        $errorOutputs = [];
        $statusDescriptions = [];

        /** @var ICodeceptWrapper $failedTest */
        foreach ($failedTests as $failedTest)
        {
            $processedTests->push($failedTest);
            $outputs            []= $failedTest->getOutput();
            $errorOutputs       []= $failedTest->getErrorOutput();
            $statusDescriptions []= $failedTest . ': ' . $failedTest->getStatusDescription();
        }

        $removeEmpty = function ($v) {return $v !== '';};

        $this->output = implode(PHP_EOL, array_filter($outputs, $removeEmpty));
        $this->errorOutput = implode(PHP_EOL, array_filter($errorOutputs, $removeEmpty));
        $this->statusDescription = implode(PHP_EOL, array_filter($statusDescriptions, $removeEmpty));

        return $processedTests;
    }

    protected function updateActualDuration() : void
    {
        if ($this->isFirstRun())
        {
            /** @var TestRecord $testRecord */
            foreach ($this->cest->getPassedTestRecords() as $testRecord)
            {
                $this->actualDuration += $testRecord->getTime();
            }

            return;
        }

        foreach ($this->runner->getPassedTestsDuration() as $testName => $duration)
        {
            $this->actualDuration += $duration;
        }
    }

    protected function updateExpectedDuration() : void
    {
        if ($this->isFirstRun())
        {
            $totalDuration = $this->cest->getExpectedDuration();

            /** @var TestRecord $testRecord */
            foreach ($this->cest->getPassedTestRecords() as $testRecord)
            {
                $totalDuration -= $testRecord->getTime();
            }

            $this->expectedDuration = $totalDuration > 0 ? $totalDuration : 1;
            return;
        }

        foreach ($this->runner->getPassedTestsDuration() as $testName => $duration)
        {
            $this->expectedDuration -= $duration;
        }

        $this->expectedDuration = $this->expectedDuration > 0 ? $this->expectedDuration : 1;
    }

    protected function parseFailedTestRecords() : void
    {
        /** @var TestRecord $testRecord */
        foreach ($this->cest->getFailedTestRecords() as $testRecord)
        {
            $test = $this->wrapperFactory->getTestWrapper($this->cestName, $testRecord->getMethod());
            $this->failedTests->push($test);
        }
    }

    public function isTimedOut() : bool
    {
        if ($this->isFirstRun())
        {
            return $this->cest->isTimedOut();
        }

        return $this->failedTests->isEmpty() && !$this->runner->getTimedOutTests()->isEmpty();
    }

    public function isSuccessful() : bool
    {
        if ($this->isFirstRun())
        {
            return $this->cest->isSuccessful();
        }

        return $this->failedTests->isEmpty() && $this->runner->getTimedOutTests()->isEmpty();
    }

    public function getOutput() : string
    {
        if ($this->isFirstRun())
        {
            return $this->cest->getOutput();
        }

        return $this->output;
    }

    public function getErrorOutput() : string
    {
        if ($this->isFirstRun())
        {
            return $this->cest->getErrorOutput();
        }

        return $this->errorOutput;
    }

    public function getStatusDescription() : string
    {
        if ($this->isFirstRun())
        {
            return $this->cest->getStatusDescription();
        }

        return $this->statusDescription;
    }

    public function matches(TestNameParts $nameParts) : bool
    {
        return $this->cest->matches($nameParts);
    }

    public function getMatch(TestNameParts $nameParts) : ?string
    {
        return $this->cest->getMatch($nameParts);
    }

    public function getExpectedDuration() : ?int
    {
        if ($this->isFirstRun())
        {
            return $this->cest->getExpectedDuration();
        }

        return ceil($this->expectedDuration);
    }

    public function setExpectedDuration(int $expectedDuration) : void
    {
        $this->cest->setExpectedDuration($expectedDuration);
    }

    public function getActualDuration() : ?int
    {
        if ($this->isFirstRun())
        {
            return $this->cest->getActualDuration();
        }

        return ceil($this->actualDuration);
    }

    public function __toString()
    {
        return (string) $this->cest;
    }

    public function hash()
    {
        return $this->cest->hash();
    }

    public function equals($obj) : bool
    {
        return $this->cest->equals($obj);
    }

    public function __clone()
    {
        $this->reset();

        $this->cest = clone $this->cest;
        $this->failedTests = new Queue();
    }
}