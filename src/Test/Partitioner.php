<?php


namespace Paracetamol\Test;


use Ds\Map;
use Ds\PriorityQueue;
use Ds\Queue;
use Ds\Stack;
use Ds\Vector;
use Paracetamol\Exceptions\UsageException;
use Paracetamol\Log\Log;
use Paracetamol\Settings\SettingsRun;
use Paracetamol\Test\CodeceptWrapper\ICodeceptWrapper;

class Partitioner
{
    protected Log         $log;
    protected SettingsRun $settings;

    public function __construct(Log $log, SettingsRun $settings)
    {
        $this->log = $log;
        $this->settings = $settings;
    }

    /**
     * @param Queue $tests
     *
     * @return Queue[]
     */
    public function simple(Queue $tests) : array
    {
        $this->log->veryVerbose('Using tests count for partitioning');

        $this->settings->setMaxRunDuration(0);

        $queueLength = ceil($tests->count() / $this->settings->getProcessCount());

        $result = [];

        for ($i = 0; $i < $this->settings->getProcessCount(); $i++)
        {
            $queue = new Queue();

            for ($j = 0; $j < $queueLength; $j++)
            {
                if ($tests->isEmpty())
                {
                    break;
                }

                $queue->push($tests->pop());
            }

            $result[$i] = $queue;
        }

        return $result;
    }

    /**
     * @param Queue $tests
     * @param Map $testNameToDuration
     * @return Queue[]
     * @throws UsageException
     */
    public function statBased(Queue $tests) : array
    {
        $this->log->veryVerbose('Using tests duration statistics for partitioning');

        $testCount = $tests->count();

        $durations = new Vector();
        $durationToTestName = new Map();

        /** @var ICodeceptWrapper $test */
        foreach ($tests as $test)
        {
            $duration = $test->getExpectedDuration();

            if ($duration === null)
            {
                throw new UsageException('Fetch expected durations for tests before partition them');
            }

            if (!$durationToTestName->hasKey($duration))
            {
                $durationToTestName->put($duration, new Queue());
            }

            /** @var Queue $testsWithDuration */
            $testsWithDuration = $durationToTestName->get($duration);
            $testsWithDuration->push($test);

            $durations->push($duration);
        }

        $durations->sort();

        $this->settings->setMinTestDurationSec($durations->first());
        $this->settings->setMedianTestDurationSec($durations[intdiv($durations->count(), 2)]);
        $this->settings->setMaxTestDurationSec($durations->last());

        $durationsPartitioned = $this->smartPartition($durations, min($this->settings->getProcessCount(), $testCount));

        $result = [];

        $maxRunDuration = 0;

        foreach ($durationsPartitioned as $durationsArray)
        {
            $queue = new Queue();

            $totalRunDuration = 0;

            foreach ($durationsArray as $duration)
            {
                /** @var Queue $testsWithDuration */
                $testsWithDuration = $durationToTestName->get($duration);
                $queue->push($testsWithDuration->pop());

                $totalRunDuration += $duration;
            }

            $result []= $queue;

            $this->log->debug('Duration of the queue: ' . $totalRunDuration);

            $maxRunDuration = $maxRunDuration > $totalRunDuration ? $maxRunDuration : $totalRunDuration;
        }

        $this->log->debug('Max run duration: ' . $maxRunDuration);

        $this->settings->setMaxRunDuration($maxRunDuration);

        $this->notifyAboutLongTest($result);

        return $result;
    }

    /**
     * @param Queue[] $queues
     */
    protected function notifyAboutLongTest(array $queues) : void
    {
        $queuesWithOnlyOneTest = [];
        $shouldNotify = false;

        foreach ($queues as $queue)
        {
            if ($queue->count() !== 1)
            {
                $shouldNotify = true;
                continue;
            }

            $queuesWithOnlyOneTest []= $queue;
        }

        if (empty($queuesWithOnlyOneTest))
        {
            return;
        }

        if (!$shouldNotify) // in each queue there is only one test
        {
            return;
        }

        /** @var ICodeceptWrapper|null $testWithLongestDuration */
        $testWithLongestDuration = null;

        foreach ($queuesWithOnlyOneTest as $queue)
        {
            /** @var ICodeceptWrapper $test */
            $test = $queue->peek();

            if ($testWithLongestDuration === null)
            {
                $testWithLongestDuration = $test;
                continue;
            }

            if ($testWithLongestDuration->getExpectedDuration() < $test->getExpectedDuration())
            {
                $testWithLongestDuration = $test;
            }
        }

        $this->log->note("Test $testWithLongestDuration takes {$testWithLongestDuration->getExpectedDuration()} seconds and a whole process to run. The run duration is determined by this test.");
    }

    protected function smartPartition(Vector $vector, int $k) : array
    {
        $length = $vector->count();

        if ($length > 4096000)
        {
            throw new UsageException("Partitioning of $length tests will take too much time and RAM to be useful");
        }

        $threshold = $k * 18000 - 256000;

        if ($threshold > 0 && $length >= $threshold)
        {
            return $this->karmarkarKarp($vector, $k);
        }

        return $this->greedy($vector, $k);
    }

    protected function greedy(Vector $vector, int $k) : array
    {
        $this->log->veryVerbose('Partitioning tests using the greedy algorithm');

        $result = new PriorityQueue();

        for ($i = 0; $i < $k; $i++)
        {
            $result->push(new Vector(), 0);
        }

        //$vector->sort();

        while (!$vector->isEmpty())
        {
            $number = $vector->pop();
            /** @var Vector $smallestSumVector */
            $smallestSumVector = $result->pop();

            $smallestSumVector->push($number);
            $sum = $smallestSumVector->sum();
            $result->push($smallestSumVector, -$sum);
        }

        return $result->toArray();
    }

    protected function karmarkarKarp(Vector $vector, int $k) : array
    {
        $this->log->veryVerbose('Partitioning tests using the Karmarkar-Karp algorithm');

        $id = PHP_INT_MIN;

        $heap = new PriorityQueue();
        $idToNumbers = new Map();
        $idToSum = new Map();

        /**
         * Convert every number into an ID that is connected with a numbers array using $idToNumbers map
         * and with a sum using $idToSum map
         */
        while (!$vector->isEmpty())
        {
            $number = $vector->pop();
            $idToNumbers[$id] = new Stack([$number]);
            $idToSum[$id] = $number;
            $heap->push([$id], $number);
            ++$id;
        }

        $sumToId = [];

        while ($heap->count() > 1)
        {
            /** @var array $a */
            $a = $heap->pop();
            /** @var array $b */
            $b = $heap->pop();

            for ($i = 0; $i < $k; $i++)
            {
                $reverseI = $k - 1 - $i;

                if (!isset($a[$i]) && !isset($b[$reverseI])) // Instead of filling k-tuple with zeroes just check that a position is set
                {
                    continue;
                }

                if (!isset($a[$i]) || !isset($b[$reverseI]))
                {
                    $Ai = $a[$i] ?? $b[$reverseI];
                    unset($a[$i], $b[$reverseI]);
                    $sum = $idToSum[$Ai];

                    isset($sumToId[$sum]) ? $sumToId[$sum] []= $Ai : $sumToId[$sum] = [$Ai];

                    continue;
                }

                /** @var int $Ai */
                $Ai = $a[$i];

                /** @var int $Bk */
                $Bk = $b[$reverseI];

                unset($a[$i], $b[$reverseI]);

                $aNumbers = $idToNumbers[$Ai];
                $bNumbers = $idToNumbers[$Bk];

                while (!$bNumbers->isEmpty())
                {
                    $aNumbers->push($bNumbers->pop());
                }

                $sum = $idToSum[$Ai] + $idToSum[$Bk];

                $idToSum[$Ai] = $sum;

                isset($sumToId[$sum]) ? $sumToId[$sum] []= $Ai : $sumToId[$sum] = [$Ai];

                $idToNumbers->remove($Bk);
                $idToSum->remove($Bk);
            }

            krsort($sumToId); // It's faster than using usort() to sort $a by sums in $idToSum map

            $a = array_merge(...$sumToId);

            $sumToId = [];

            $difference = $idToSum[$a[0]] - (isset($a[$k -1]) ? $idToSum[$a[$k -1]] : 0);

            $heap->push($a, $difference);
        }

        /** @var array $last */
        $last = $heap->pop();

        array_walk($last, function (&$item) use ($idToNumbers) {
            $item = $idToNumbers[$item];
        });

        return $last;
    }
}
