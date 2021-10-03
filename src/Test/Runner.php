<?php


namespace Paracetamol\Test;


use Ds\Map;
use Ds\Queue;
use Paracetamol\Log\Log;
use Paracetamol\Test\CodeceptWrapper\ICodeceptWrapper;

class Runner
{
    protected Log      $log;
    protected Delayer  $delayer;

    protected Queue    $queue;
    protected Queue    $failedTests;
    protected Queue    $timedOutTests;
    protected Queue    $markedSkippedTests;
    protected Map      $passedTestsDuration;

    protected ?ICodeceptWrapper $currentTest = null;

    protected string   $label = '';
    protected bool     $progressAllowed = true;


    public function __construct(Log $log, Delayer $delayer, Queue $queue)
    {
        $this->log = $log;
        $this->delayer = $delayer;

        $this->queue = $queue;
        $this->failedTests = new Queue();
        $this->timedOutTests = new Queue();
        $this->markedSkippedTests = new Queue();
        $this->passedTestsDuration = new Map();
    }

    public function ticking() : bool
    {
        if ($this->currentTest === null)
        {
            if ($this->hasEmptyQueue())
            {
                return false;
            }

            if ($this->delayer->allowsTestStart())
            {
                $this->currentTest = $this->popQueue();
                $this->currentTest->start();
            }

            return true;
        }

        if ($this->currentTest->isRunning())
        {
            return true;
        }

        if ($this->currentTest->isSuccessful())
        {
            $this->passedTestsDuration->put((string) $this->currentTest, $this->currentTest->getActualDuration());

            $this->log->verbose('[PASS] ' . $this->currentTest . " {$this->getLabel()}");

            if ($this->progressAllowed)
            {
                $this->log->progressAdvance();
            }

            $this->currentTest = null;
            return true;
        }

        if (!$this->currentTest->isSuccessful())
        {
            if ($this->currentTest->isTimedOut())
            {
                $this->log->verbose('     [TIMEOUT] ' . $this->currentTest . " {$this->getLabel()}");
                $this->timedOutTests->push($this->currentTest);
            }
            elseif ($this->currentTest->isMarkedSkipped())
            {
                $this->log->verbose('     [MARKED_SKIPPED] ' . $this->currentTest . " {$this->getLabel()}");
                $this->markedSkippedTests->push($this->currentTest);
            }
            else
            {
                $this->log->verbose('     [FAIL] ' . $this->currentTest . " {$this->getLabel()}");
                $this->failedTests->push($this->currentTest);
            }

            $this->currentTest = null;
            return true;
        }

        return true;
    }

    public function forbidAdvancingProgressBar() : void
    {
        $this->progressAllowed = false;
    }

    public function setLabel(string $label) : Runner
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel() : string
    {
        return $this->label;
    }

    public function hasEmptyQueue() : bool
    {
        return $this->queue->isEmpty();
    }

    public function popQueue() : ICodeceptWrapper
    {
        return $this->queue->pop();
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
    public function getPassedTestsDuration() : Map
    {
        return $this->passedTestsDuration;
    }
}
