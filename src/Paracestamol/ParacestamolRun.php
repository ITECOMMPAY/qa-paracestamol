<?php


namespace Paracestamol\Paracestamol;


use Codeception\Util\Autoload;
use DateInterval;
use Ds\Map;
use Ds\Queue;
use Paracestamol\Exceptions\GeneralException;
use Paracestamol\Exceptions\SerialBeforeFailedException;
use Paracestamol\Helpers\TestNameParts;
use Paracestamol\Log\Log;
use Paracestamol\Settings\SettingsRun;
use Paracestamol\Test\CodeceptWrapper\AbstractCodeceptWrapper;
use Paracestamol\Test\Delayer;
use Paracestamol\Test\Partitioner;
use Paracestamol\Test\Loader;
use Paracestamol\Test\RunnersSupervisor;
use Paracestamol\Test\RunnersSupervisorFactory;
use Paracestamol\Test\Statistics;
use Paracestamol\Test\CodeceptWrapper\ICodeceptWrapper;
use Paracestamol\Test\CodeceptWrapper\Wrapper\TestWrapper;
use Symfony\Component\Process\Process;

class ParacestamolRun
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
        if (!$this->rebuildActor())
        {
            throw new GeneralException('Can\'t rebuild the codeception actor');
        }

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

        $failedTests [] = $this->runInSeries(  'Before', $runBeforeInSeries,   $runCount);

        if ($this->settings->isSerialBeforeFailsRun() && !$failedTests[0]->isEmpty())
        {
            $this->processFailedTests($failedTests);
            throw new SerialBeforeFailedException('Serial before is failed - stopping the script');
        }

        $failedTests [] = $this->runInParallel('Before', $runBeforeInParallel, $runCount, $this->settings->isContinuousRerun());
        $failedTests [] = $this->runInParallel('Main',   $runMain,             $runCount, $this->settings->isContinuousRerun());
        $failedTests [] = $this->runInParallel('After',  $runAfterInParallel,  $runCount, $this->settings->isContinuousRerun());
        $failedTests [] = $this->runInSeries(  'After',  $runAfterInSeries,    $runCount);

        $this->processFailedTests($failedTests);
    }

    protected function rebuildActor() : bool
    {
        //It seems that Codeception' build command is abandoned. It has poor documentation and it fails if a module
        //doesn't have a default config and expects env files instead.
        //So, to generate an Actor class let's just execute 'codecept run' with a non-existing group

        $codeceptionBin = $this->settings->getCodeceptionBinPath();

        $suite = $this->settings->getSuite();

        $runOptions = [
            '--config', $this->settings->getCodeceptionConfigPath(),
            '-o',       'paths: output: ' . $this->settings->getRunOutputPath(),
            '--no-colors',
            '--no-interaction',
            '-g', 'paracestamolSpecialNonExistingGroup'
        ];

        if (!empty($this->settings->getEnv()))
        {
            $runOptions []= '--env';
            $runOptions []= $this->settings->getEnvAsString();
        }

        if (!empty($this->settings->getOverride()))
        {
            $runOptions []= '-o';
            $runOptions []= $this->settings->getOverrideAsString();
        }

        $cmd = ['php', $codeceptionBin, 'run', $suite, ...$runOptions];

        $proc = new Process($cmd);
        $proc->setTimeout(null);
        $proc->setIdleTimeout( null);
        $proc->run();

        if (!$proc->isSuccessful())
        {
            $this->log->note($proc->getErrorOutput());
        }

        return $proc->isSuccessful();
    }

    protected function runInParallel(string $runName, Queue $tests, int $runCount, bool $continuousRerun) : Queue
    {
        if ($tests->isEmpty())
        {
            return $tests;
        }

        $this->log->section($runName . ' Parallel Run');

        $this->log->progressStart($tests->count());

        $testsNoRerun = new Queue();

        $saveTestsWithForbiddenRerun = function (RunnersSupervisor $runSupervisor) use ($testsNoRerun)
        {
            foreach ($runSupervisor->getFailedTestsNoRerun() as $test)
            {
                $testsNoRerun->push($test);
            }

            foreach ($runSupervisor->getTimedOutTests() as $test)
            {
                $testsNoRerun->push($test);
            }
        };

        if ($continuousRerun)
        {
            $runCount = 1; // Supervisor will rerun tests on its own
        }

        for ($i = 0; $i < $runCount; $i++)
        {
            if ($tests->isEmpty())
            {
                $this->log->verbose("No more tests to run in the parallel '$runName' group");
                break;
            }

            $this->log->verbose($continuousRerun ? 'Run continuous' : 'Run ' . ($i+1) . ' of '. $runCount);

            $queues = $this->partitionInQueues($tests);
            $this->setAdaptiveDelay();
            $this->outputExpectedRunDuration();

            $runSupervisor = $this->runnersSupervisorFactory->get($queues, $continuousRerun);
            $runSupervisor->run();

            $this->sendTestsDurations($runSupervisor->getPassedTestsDurations());

            $saveTestsWithForbiddenRerun($runSupervisor);

            $tests = $runSupervisor->getFailedTests();

            $this->log->verbose('');
        }

        $this->log->newLine(2);

        foreach ($testsNoRerun as $test)
        {
            $tests->push($test);
        }

        return $tests;
    }

    protected function runInSeries(string $runName, Queue $tests, int $runCount) : Queue
    {
        if ($tests->isEmpty())
        {
            return $tests;
        }

        $this->log->section($runName . ' Serial Run');

        $this->log->progressStart($tests->count());

        $testsNoRerun = new Queue();

        $saveTestsWithForbiddenRerun = function (RunnersSupervisor $runSupervisor) use ($testsNoRerun)
        {
            foreach ($runSupervisor->getFailedTestsNoRerun() as $test)
            {
                $testsNoRerun->push($test);
            }

            foreach ($runSupervisor->getTimedOutTests() as $test)
            {
                $testsNoRerun->push($test);
            }
        };

        if ($this->settings->isAdaptiveDelay())
        {
            $this->settings->setDelayMsec(1000);
        }

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

            $runSupervisor = $this->runnersSupervisorFactory->get([$tests], false);
            $runSupervisor->run();

            $this->sendTestsDurations($runSupervisor->getPassedTestsDurations());

            $saveTestsWithForbiddenRerun($runSupervisor);

            $tests = $runSupervisor->getFailedTests();

            if ($this->settings->isRerunWholeSeries() && !$tests->isEmpty())
            {
                $tests = $clonedTests;
            }

            $this->log->verbose('');
        }

        $this->log->newLine(2);

        foreach ($testsNoRerun as $test)
        {
            $tests->push($test);
        }

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

        if ($this->settings->isSuccessfullyFetchedDurations() && ($tests->count() > $this->settings->getProcessCount()))
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

    protected function sendTestsDurations(Map $testNameToDuration) : void
    {
        if (empty($this->settings->getStatEndpoint()))
        {
            return;
        }

        if ($testNameToDuration->isEmpty())
        {
            return;
        }

        try
        {
            $this->statistics->sendActualDurations($testNameToDuration);
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
    protected function processFailedTests(array $queues) : void
    {
        $report = [];
        $failedTests = new Queue();

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
                    'message' => $message,
                ];

                $report []= $record;

                $failedTests->push($test);
            }
        }

        $this->printFailReport($report);

        $this->saveFailReport($report);

        $this->sendNeverPassedTestsDurations($failedTests);
    }

    protected function printFailReport(array $report) : void
    {
        if (empty($report))
        {
            return;
        }

        $logLines = [];

        $getTrimmedLines = function (string $message) {
            $trimLine = function (string $line) {return mb_strlen($line) > 96 ? (mb_substr($line, 0, 92) . ' ...') : $line;};
            return array_map($trimLine, explode(PHP_EOL, $message));
        };

        $this->log->section('Following tests failed');

        foreach ($report as $record)
        {
            $testName = $record['name'];
            $messageLines = $getTrimmedLines($record['message']);

            if ($record['type'] === 'test')
            {
                $firstLine = reset($messageLines);
                $logLines [] = $testName . ($firstLine !== false ? ": {$firstLine}" : '');
            }
            else
            {
                array_push($logLines, ...$messageLines);
            }
        }

        sort($logLines, SORT_STRING);

        foreach ($logLines as $logLine)
        {
            $this->log->normal($logLine);
        }
    }

    protected function saveFailReport(array $report) : void
    {
        if (empty($report))
        {
            return;
        }

        $encodedReport = json_encode($report, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        file_put_contents($this->settings->getRunOutputPath() . DIRECTORY_SEPARATOR . $this->settings->getRunId() . '_failures.json', $encodedReport);
    }

    /**
     * If a test is never passed - it's better to know something about its duration than know nothing at all.
     *
     * @param Queue $failedTests
     */
    protected function sendNeverPassedTestsDurations(Queue $failedTests) : void
    {
        $failedTestsDurations = new Map();

        foreach ($failedTests as $test)
        {
            if ($test->getExpectedDuration() !== null)
            {
                continue;
            }

            if ($test->getActualDuration() === null)
            {
                continue;
            }

            $failedTestsDurations->put((string) $test, $test->getActualDuration());
        }

        $this->sendTestsDurations($failedTestsDurations);
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
