<?php


namespace Paracetamol\Paracetamol;


use Codeception\Util\Autoload;
use DateInterval;
use Ds\Map;
use Ds\Queue;
use Paracetamol\Exceptions\SerialBeforeFailedException;
use Paracetamol\Helpers\TestNameParts;
use Paracetamol\Log\Log;
use Paracetamol\Settings\SettingsRun;
use Paracetamol\Test\CodeceptWrapper\AbstractCodeceptWrapper;
use Paracetamol\Test\Delayer;
use Paracetamol\Test\Partitioner;
use Paracetamol\Test\Loader;
use Paracetamol\Test\RunnersSupervisorFactory;
use Paracetamol\Test\Statistics;
use Paracetamol\Test\CodeceptWrapper\ICodeceptWrapper;
use Paracetamol\Test\CodeceptWrapper\Wrapper\TestWrapper;

class ParacetamolRun
{
    protected SettingsRun              $settings;
    protected Log                      $log;
    protected Loader                   $loader;
    protected Partitioner              $divider;
    protected Delayer                  $delayer;
    protected RunnersSupervisorFactory $runnersSupervisorFactory;
    protected Statistics               $statistics;

    public function __construct(Log $log, SettingsRun $settings, Loader $loader, Partitioner $divider, Delayer $delayer, RunnersSupervisorFactory $runnersSupervisorFactory, Statistics $statistics)
    {
        $this->log = $log;
        $this->settings = $settings;
        $this->loader = $loader;
        $this->divider = $divider;
        $this->delayer = $delayer;
        $this->runnersSupervisorFactory = $runnersSupervisorFactory;
        $this->statistics = $statistics;
    }

    public function execute() : void
    {
        $tests = $this->parseTests();
        $tests = $this->fetchTestDurations($tests);

        [
            'runBeforeInSeries'   => $runBeforeInSeries,
            'runBeforeInParallel' => $runBeforeInParallel,
            'runMain'             => $runMain,
            'runAfterInParallel'  => $runAfterInParallel,
            'runAfterInSeries'    => $runAfterInSeries,
        ] = $this->partitionInBeforeAfterGroups($tests);

        $runBeforeInSeries = $this->sortByTestNameParts($runBeforeInSeries, new TestNameParts($this->settings->getRunBeforeSeries()));
        $runAfterInSeries  = $this->sortByTestNameParts($runAfterInSeries,  new TestNameParts($this->settings->getRunAfterSeries()));

        $runCount = 1 + $this->settings->getRerunCount();

        $failedTests = [];

        $failedTests [] = $this->runInSeries($runBeforeInSeries, $runCount, 'Before');

        if ($this->settings->isSerialBeforeFailsRun() && !$failedTests[0]->isEmpty())
        {
            $this->prepareSimpleFailReport($failedTests);
            throw new SerialBeforeFailedException('Serial before is failed - stopping the script');
        }

        $failedTests [] = $this->runInParallel($runBeforeInParallel, $runCount, 'Before');
        $failedTests [] = $this->runInParallel($runMain, $runCount, 'Main');
        $failedTests [] = $this->runInParallel($runAfterInParallel, $runCount, 'After');
        $failedTests [] = $this->runInSeries($runAfterInSeries, $runCount, 'After');

        $this->prepareSimpleFailReport($failedTests);
    }

    protected function runInParallel(Queue $tests, int $runCount, string $runName = '') : Queue
    {
        if ($tests->isEmpty())
        {
            return $tests;
        }

        $this->log->section($runName . ' Parallel Run');

        $this->log->progressStart($tests->count());

        for ($i = 0; $i < $runCount; $i++)
        {
            if ($tests->isEmpty())
            {
                $this->log->verbose("No more tests to run in the parallel '$runName' group");
                break;
            }

            $this->log->verbose('Run ' . ($i+1) . ' of '. $runCount);

            $queues = $this->partitionInQueues($tests);
            $this->setAdaptiveDelay();
            $this->outputExpectedRunDuration();

            $runSupervisor = $this->runnersSupervisorFactory->get($queues);
            $runSupervisor->run();

            $this->sendTestDurations($runSupervisor->getPassedTestsDurations());
            $tests = $this->skipRerunsForSelectedTests($runSupervisor->getFailedTests());

            $this->log->verbose('');
        }

        $this->log->newLine(2);

        return $tests;
    }

    protected function runInSeries(Queue $tests, int $runCount, string $runName = '') : Queue
    {
        if ($tests->isEmpty())
        {
            return $tests;
        }

        $this->log->section($runName . ' Serial Run');

        $this->log->progressStart($tests->count());

        for ($i = 0; $i < $runCount; $i++)
        {
            if ($tests->isEmpty())
            {
                $this->log->verbose("No more tests to run in the serial '$runName' group");
                break;
            }

            $this->log->verbose('Run ' . ($i+1) . ' of '. $runCount);

            if ($this->settings->isRerunWholeSeries())
            {
                [$tests, $clonedTests] = $this->cloneQueue($tests);
            }

            $runSupervisor = $this->runnersSupervisorFactory->get([$tests]);
            $runSupervisor->run();

            $this->sendTestDurations($runSupervisor->getPassedTestsDurations());

            $tests = $runSupervisor->getFailedTests();

            if ($this->settings->isRerunWholeSeries() && !$tests->isEmpty())
            {
                $tests = $clonedTests;
            }

            $tests = $this->skipRerunsForSelectedTests($tests);

            $this->log->verbose(PHP_EOL);
        }

        $this->log->newLine(2);

        return $tests;
    }

    protected function parseTests() : Queue
    {
        $this->log->verbose("Parsing tests");

        $this->log->veryVerbose('Loading classes from the support directory');
        Autoload::addNamespace($this->settings->getNamespace(), $this->settings->getSupportPath());

        $tests = $this->loader->getTests();

        $this->log->veryVerbose("{$tests->count()} tests found");

        return $tests;
    }

    /**
     * @param Queue $tests
     * @return Queue[]
     */
    protected function partitionInBeforeAfterGroups(Queue $tests) : array
    {
        $this->log->veryVerbose("Parsing tests in before and after groups");

        $runBeforeInSeries   = new Queue();
        $runBeforeInParallel = new Queue();
        $runMain             = new Queue();
        $runAfterInParallel  = new Queue();
        $runAfterInSeries    = new Queue();

        $namesOfBeforeInSeries   = new TestNameParts($this->settings->getRunBeforeSeries());
        $namesOfBeforeInParallel = new TestNameParts($this->settings->getRunBeforeParallel());
        $namesOfAfterInParallel  = new TestNameParts($this->settings->getRunAfterParallel());
        $namesOfAfterInSeries    = new TestNameParts($this->settings->getRunAfterSeries());

        /** @var ICodeceptWrapper $test */
        foreach ($tests as $test)
        {
            if ($test->matches($namesOfBeforeInSeries))
            {
                $runBeforeInSeries->push($test);
                continue;
            }

            if ($test->matches($namesOfBeforeInParallel))
            {
                $runBeforeInParallel->push($test);
                continue;
            }

            if ($test->matches($namesOfAfterInParallel))
            {
                $runAfterInParallel->push($test);
                continue;
            }

            if ($test->matches($namesOfAfterInSeries))
            {
                $runAfterInSeries->push($test);
                continue;
            }

            $runMain->push($test);
        }

        $this->log->debug(<<<HEREDOC
runBeforeInSeries:   {$runBeforeInSeries->count()},
runBeforeInParallel: {$runBeforeInParallel->count()},
runMain:             {$runMain->count()},
runAfterInParallel:  {$runAfterInParallel->count()},
runAfterInSeries:    {$runAfterInSeries->count()},
HEREDOC
        );

        return [
            'runBeforeInSeries'   => $runBeforeInSeries,
            'runBeforeInParallel' => $runBeforeInParallel,
            'runMain'             => $runMain,
            'runAfterInParallel'  => $runAfterInParallel,
            'runAfterInSeries'    => $runAfterInSeries,
        ];
    }

    protected function sortByTestNameParts(Queue $tests, TestNameParts $parts) : Queue
    {
        $nameToPosition = array_flip($parts->getStrings());

        $result = [];

        /** @var AbstractCodeceptWrapper $test */
        foreach ($tests as $test)
        {
            $name = $test->getMatch($parts);

            $position = $nameToPosition[$name];

            $result[$position] = $test;
        }

        ksort($result, SORT_NUMERIC);

        return new Queue($result);
    }

    /**
     * @param Map $tests
     *
     * @return Queue[]
     */
    protected function partitionInQueues(Queue $tests) : array
    {
        $this->log->veryVerbose("{$tests->count()} tests will be partitioned in {$this->settings->getProcessCount()} queues");

        if ($this->settings->isSuccessfullyFetchedDurations())
        {
            return $this->divider->statBased($tests);
        }

        return $this->divider->simple($tests);
    }

    protected function fetchTestDurations(Queue $tests)
    {
        if (empty($this->settings->getStatEndpoint()))
        {
            return $tests;
        }

        try
        {
            return $this->statistics->fetchExpectedDurations($tests);
        }
        catch (\Exception $e)
        {
            $this->log->warning('Can\'t get tests durations because of the following error: ' . $e->getMessage());
        }

        return $tests;
    }

    protected function sendTestDurations(Map $passedTestsDuration)
    {
        if (empty($this->settings->getStatEndpoint()))
        {
            return;
        }

        if ($passedTestsDuration->isEmpty())
        {
            return;
        }

        try
        {
            $this->statistics->sendActualDurations($passedTestsDuration);
        }
        catch (\Exception $e)
        {
            $this->log->warning('Can\'t send tests durations because of the following error: ' . $e->getMessage());
        }
    }

    protected function setAdaptiveDelay()
    {
        if (!$this->settings->isAdaptiveDelay())
        {
            return;
        }

        $rps = min($this->settings->getProcessCount(), $this->settings->getMaxRps());

        $rpsDelay = floor(1000 / $rps);

        $this->settings->setDelayMsec($rpsDelay);

        $this->log->veryVerbose("Adaptive delay is set to {$this->settings->getDelaySeconds()} seconds");
    }

    protected function skipRerunsForSelectedTests(Queue $tests) : Queue
    {
        $shouldBeSkipped = new TestNameParts($this->settings->getSkipReruns());

        $result = new Queue();

        /** @var ICodeceptWrapper $test */
        foreach ($tests as $test)
        {
            if ($test->matches($shouldBeSkipped))
            {
                continue;
            }

            $result->push($test);
        }

        return $result;
    }

    protected function outputExpectedRunDuration()
    {
        $duration = $this->settings->getMaxRunDuration();

        if ($duration === 0)
        {
            return;
        }

        $endTime = (new \DateTime())->add(new DateInterval("PT{$duration}S"))->format(\DateTimeInterface::COOKIE);

        $this->log->verbose('Tests will end approximately at ' . $endTime);
    }

    /**
     * @param Queue[] $queues
     */
    protected function prepareSimpleFailReport(array $queues)
    {
        $report = [];

        $trimLines = function (string $value) {
            return implode(PHP_EOL, array_filter(array_map('trim', explode(PHP_EOL, $value)), function ($v) {return $v !== '';}));
        };

        foreach ($queues as $queue)
        {
            /** @var ICodeceptWrapper $test */
            foreach ($queue as $test)
            {
                $type = $test instanceof TestWrapper ? 'test' : 'cest';
                $testName = (string) $test;
                $message = $test->getStatusDescription();

                $record = [
                    'type'    => $type,
                    'name'    => $testName,
                    'message' => $trimLines($message),
                ];

                $report []= $record;
            }
        }

        $trimLongLines = function (string $value) {
            return implode(PHP_EOL, array_map(function ($v) {return mb_strlen($v) > 96 ? (mb_substr($v, 0, 92) . ' ...') : $v;}, explode(PHP_EOL, $value)));
        };

        $logLines = [];

        if (!empty($report))
        {
            $this->log->section('Following tests failed');

            foreach ($report as $record)
            {
                $testName = $record['name'];
                $message = $trimLongLines($record['message']);

                if ($record['type'] === 'test')
                {
                    $logLines []= $testName . (!empty($message) ? ": {$message}" : '');
                }
                else
                {
                    $logLines []= $message;
                }
            }
        }

        sort($logLines, SORT_STRING);

        foreach ($logLines as $logLine)
        {
            $this->log->normal($logLine);
        }

        $report = json_encode($report, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        file_put_contents($this->settings->getRunOutputPath() . DIRECTORY_SEPARATOR . $this->settings->getRunId() . '_failures.json', $report);
    }

    protected function cloneQueue(Queue $queue) : array
    {
        $original = new Queue();
        $clone    = new Queue();

        /** @var AbstractCodeceptWrapper $codeceptWrapper */
        foreach ($queue as $codeceptWrapper)
        {
            $original->push($codeceptWrapper);
            $clone->push(clone $codeceptWrapper);
        }

        return [$original, $clone];
    }
}