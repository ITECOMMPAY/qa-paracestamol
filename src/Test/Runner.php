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
    protected Map      $passedTestsDuration;

    protected ?ICodeceptWrapper $currentTest = null;


    public function __construct(Log $log, Delayer $delayer, Queue $queue)
    {
        $this->log = $log;
        $this->delayer = $delayer;

        $this->queue = $queue;
        $this->failedTests = new Queue();
        $this->timedOutTests = new Queue();
        $this->passedTestsDuration = new Map();
    }

    public function ticking() : bool
    {
        if ($this->currentTest === null)
        {
            if ($this->queue->isEmpty())
            {
                return false;
            }

            if ($this->delayer->allowsTestStart())
            {
                $this->currentTest = $this->queue->pop();
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

            $this->log->verbose('[PASS] ' . $this->currentTest);
            $this->log->progressAdvance();
            $this->currentTest = null;
            return true;
        }

        if (!$this->currentTest->isSuccessful())
        {
            if ($this->currentTest->isTimedOut())
            {
                $this->log->verbose('     [TIMEOUT] ' . $this->currentTest);
                $this->timedOutTests->push($this->currentTest);
            }
            else
            {
                $this->log->verbose('     [FAIL] ' . $this->currentTest);
                $this->failedTests->push($this->currentTest);
            }

            $this->currentTest = null;
            return true;
        }

        return true;
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
     * @return Map - [testName => duration]
     */
    public function getPassedTestsDuration() : Map
    {
        return $this->passedTestsDuration;
    }
}
