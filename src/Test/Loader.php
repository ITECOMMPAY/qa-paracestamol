<?php


namespace Paracetamol\Test;

use Ds\Queue;
use Ds\Set;
use Paracetamol\Helpers\TestNameParts;
use Paracetamol\Log\Log;
use Paracetamol\Settings\SettingsRun;
use Paracetamol\Test\CodeceptWrapper\AbstractCodeceptWrapper;
use Paracetamol\Test\CodeceptWrapper\ICodeceptWrapper;
use Paracetamol\Test\CodeceptWrapper\CodeceptWrapperFactory;

class Loader
{
    protected Log            $log;
    protected SettingsRun    $settings;
    protected ParserWrapper $parserWrapper;
    protected CodeceptWrapperFactory $wrapperFactory;

    protected TestNameParts $onlyTests;
    protected TestNameParts $skipTests;
    protected TestNameParts $notDividableCestsWhole;
    protected TestNameParts $notDividableCestsOnlyFailed;

    public function __construct(Log $log, SettingsRun $settings, ParserWrapper $parserWrapper, CodeceptWrapperFactory $wrapperFactory)
    {
        $this->log = $log;
        $this->settings = $settings;
        $this->parserWrapper = $parserWrapper;
        $this->wrapperFactory = $wrapperFactory;

        $this->onlyTests = new TestNameParts([]);
        $this->skipTests = new TestNameParts([]);
        $this->notDividableCestsWhole = new TestNameParts([]);
        $this->notDividableCestsOnlyFailed = new TestNameParts([]);
    }

    /**
     * @return Queue - [ICodeceptWrapper]
     */
    public function getTests() : Queue
    {
        $this->log->veryVerbose('Searching for tests');

        if (!empty($this->settings->getNotDividableRerunWhole()))
        {
            $this->log->veryVerbose('Some cests will not be divided and in the case of a failure will be rerunned as a whole (as per \'not_dividable_rerun_whole\' setting). ');
            $this->notDividableCestsWhole = new TestNameParts($this->settings->getNotDividableRerunWhole());
        }

        if (!empty($this->settings->getNotDividableRerunFailed()))
        {
            $this->log->veryVerbose('Some cests will not be divided and in the case of a failure only the failed tes will be rerunned (as per \'not_dividable_rerun_failed\' setting). ');
            $this->notDividableCestsOnlyFailed = new TestNameParts($this->settings->getNotDividableRerunFailed());
        }

        if (!empty($this->settings->getOnlyTests()))
        {
            $this->log->veryVerbose('Only tests that are mentioned in \'only_tests\' setting will be loaded');
            $this->onlyTests = new TestNameParts($this->settings->getOnlyTests());
        }

        if (!empty($this->settings->getSkipTests()))
        {
            $this->log->veryVerbose('Some tests will be skipped (as per \'skip_tests\' setting)');
            $this->skipTests = new TestNameParts($this->settings->getSkipTests());
        }

        if (!$this->settings->getGroups()->isEmpty())
        {
            $this->log->veryVerbose('Look only for tests with groups: ' . json_encode($this->settings->getGroups()->toArray()));
        }

        return $this->parseTests();
    }

    protected function parseTests() : Queue
    {
        $parsedTestsResult = $this->parserWrapper->parse();

        if (empty($parsedTestsResult['data']['cests']))
        {
            $this->log->debug('No tests found');
            return new Queue();
        }

        $tests = $this->loadCests($parsedTestsResult['data']['cests']);

        return $this->filterByOnlyTests($tests);
    }

    protected function loadCests(array $cests) : Queue
    {
        $result = new Queue();

        foreach ($cests as $cestName => $cestData)
        {
            $path = dirname($cestName);

            if ($this->skipTests->getCests()->contains($cestName) || $this->skipTests->getPaths()->contains($path))
            {
                $this->log->debug($cestName . ' -> skipped (in skip_tests)');
                continue;
            }

            if (!empty($cestData['error']))
            {
                $this->log->note("$cestName - skipped because of the error: " . json_encode($cestData['error']));
                continue;
            }

            if (empty($cestData['tests']))
            {
                $this->log->note("No tests found in: $cestName");
                continue;
            }

            $tests = $this->loadTests($path, $cestName, $cestData);

            /** @var ICodeceptWrapper $test */
            foreach ($tests as $test)
            {
                $result->push($test);
            }
        }

        return $result;
    }

    protected function loadTests(string $path, string $cestName, array $cestData) : Queue
    {
        $result = new Queue();

        $cestGroups = new Set($cestData['groups'] ?? []);

        $expectedGroups = $this->settings->getGroups();

        $tests = $cestData['tests'];

        foreach ($tests as $testClass => $methods)
        {
            if ($this->notDividableCestsWhole->getCests()->contains($cestName) || $this->notDividableCestsWhole->getPaths()->contains($path))
            {
                $actualGroups = $cestGroups->union($this->collectMethodGroups($methods));

                $this->loadWholeCest($result, $cestName, $actualGroups, $expectedGroups);

                continue;
            }

            if ($this->notDividableCestsOnlyFailed->getCests()->contains($cestName) || $this->notDividableCestsOnlyFailed->getPaths()->contains($path))
            {
                $actualGroups = $cestGroups->union($this->collectMethodGroups($methods));

                $this->loadClusterCest($result, $cestName, $actualGroups, $expectedGroups);

                continue;
            }

            foreach ($methods as $methodName => $testData)
            {
                $actualGroups = $cestGroups->union(new Set($testData['groups'] ?? []));

                $this->loadTest($result, $cestName, $methodName, $actualGroups, $expectedGroups);
            }
        }

        return $result;
    }

    protected function notInExpectedGroups(Set $actualGroups, Set $expectedGroups) : bool
    {
        return !$expectedGroups->isEmpty() && $actualGroups->intersect($expectedGroups)->isEmpty();
    }

    protected function loadWholeCest(Queue $queue, string $cestName, Set $actualGroups, Set $expectedGroups) : void
    {
        if ($this->notInExpectedGroups($actualGroups, $expectedGroups))
        {
            $this->log->debug($cestName . ' -> skipped (not in a group)');
            return;
        }

        $test = $this->wrapperFactory->getCestWrapper($cestName, $actualGroups, $expectedGroups);

        $queue->push($test);
    }

    protected function loadClusterCest(Queue $queue, string $cestName, Set $actualGroups, Set $expectedGroups) : void
    {
        if ($this->notInExpectedGroups($actualGroups, $expectedGroups))
        {
            $this->log->debug($cestName . ' -> skipped (not in a group)');
            return;
        }

        $test = $this->wrapperFactory->getClusterCestWrapper($cestName, $actualGroups, $expectedGroups);

        $queue->push($test);
    }

    protected function loadTest(Queue $queue, string $cestName, string $methodName, Set $actualGroups, Set $expectedGroups) : void
    {
        $testName = "$cestName:$methodName";

        if ($this->skipTests->getTests()->contains($testName))
        {
            $this->log->debug($testName . ' -> skipped (in skip_tests)');
            return;
        }

        if ($this->notInExpectedGroups($actualGroups, $expectedGroups))
        {
            $this->log->debug($testName . ' -> skipped (not in a group)');
            return;
        }

        $test = $this->wrapperFactory->getTestWrapper($cestName, $methodName);

        $queue->push($test);
    }

    protected function collectMethodGroups(array $methods) : Set
    {
        $result = new Set();

        foreach ($methods as $methodName => $testData)
        {
            $testGroups = $testData['groups'] ?? [];
            $result->add(...$testGroups);
        }

        return $result;
    }

    protected function filterByOnlyTests(Queue $tests) : Queue
    {
        if ($this->onlyTests->isEmpty())
        {
            return $tests;
        }

        $result = new Queue();

        /** @var AbstractCodeceptWrapper $test */
        foreach ($tests as $test)
        {
            if ($test->matches($this->onlyTests))
            {
                $result->push($test);
            }
        }

        return $result;
    }
}
