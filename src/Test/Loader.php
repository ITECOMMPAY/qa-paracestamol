<?php


namespace Paracestamol\Test;

use Ds\Queue;
use Ds\Set;
use Paracestamol\Helpers\TestNameParts;
use Paracestamol\Log\Log;
use Paracestamol\Settings\SettingsRun;
use Paracestamol\Test\CodeceptWrapper\AbstractCodeceptWrapper;
use Paracestamol\Test\CodeceptWrapper\ICodeceptWrapper;
use Paracestamol\Test\CodeceptWrapper\CodeceptWrapperFactory;

class Loader
{
    protected Log            $log;
    protected SettingsRun    $settings;
    protected ParserWrapper $parserWrapper;
    protected CodeceptWrapperFactory $wrapperFactory;

    protected TestNameParts $onlyTests;
    protected TestNameParts $skipTests;
    protected TestNameParts $immuneTests;
    protected TestNameParts $dividableCests;
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
        $this->immuneTests = new TestNameParts([]);
        $this->dividableCests = new TestNameParts([]);
        $this->notDividableCestsWhole = new TestNameParts([]);
        $this->notDividableCestsOnlyFailed = new TestNameParts([]);
    }

    /**
     * @return Queue - [ICodeceptWrapper]
     */
    public function getTests() : Queue
    {
        $this->log->veryVerbose('Searching for tests');

        if (!empty($this->settings->getDividable()))
        {
            $this->log->veryVerbose('Some cests will be divided into separate tests (as per \'dividable\' setting). ');
            $this->dividableCests = new TestNameParts($this->settings->getDividable());
        }

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
            $this->markCestsAsDividable($this->onlyTests);
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

        if (!empty($this->settings->getImmuneTests()))
        {
            $this->log->veryVerbose('Some tests will ignore selected groups, \'skip_tests\' and \'only_tests\' settings (as per \'immune_tests\' setting).');
            $this->immuneTests = new TestNameParts($this->settings->getImmuneTests());
            $this->markCestsAsDividable($this->immuneTests);
        }

        return $this->parseTests();
    }

    protected function markCestsAsDividable(TestNameParts $nameParts) : void
    {
        if ($this->settings->getCestWrapper() === 'tests' || $nameParts->getTests()->isEmpty())
        {
            return;
        }

        $this->log->veryVerbose('Some cests will be divided into separate tests because these tests are mentioned in \'only_tests\' or \'immune_tests\' setting');

        foreach ($nameParts->getTests() as $testName)
        {
            $end = strpos($testName, '.php:') + 4;
            $cestName = substr($testName, 0, $end);
            $this->dividableCests->getCests()->add($cestName);
        }
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

    //TODO decouple test wrapping from filtering

    protected function loadCests(array $cests) : Queue
    {
        $result = new Queue();

        foreach ($cests as $cestName => $cestData)
        {
            $path = dirname($cestName);

            if (($this->skipTests->matchesCest($cestName) || $this->skipTests->matchesPath($path))
                && !($this->immuneTests->matchesPath($path) || $this->immuneTests->matchesCest($cestName)))
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
            if ($this->dividableCests->matchesCest($cestName) || $this->dividableCests->matchesPath($path))
            {
                $this->wrapTests($result, $path, $cestName, $methods, $cestGroups, $expectedGroups);

                continue;
            }

            if ($this->notDividableCestsWhole->matchesCest($cestName) || $this->notDividableCestsWhole->matchesPath($path))
            {
                $this->wrapWholeCest($result, $path, $cestName, $methods, $cestGroups, $expectedGroups);

                continue;
            }

            if ($this->notDividableCestsOnlyFailed->matchesCest($cestName) || $this->notDividableCestsOnlyFailed->matchesPath($path))
            {
                $this->wrapClusterCest($result, $path, $cestName, $methods, $cestGroups, $expectedGroups);

                continue;
            }

            if ($this->settings->getCestWrapper() === 'tests')
            {
                $this->wrapTests($result, $path, $cestName, $methods, $cestGroups, $expectedGroups);
            }
            elseif ($this->settings->getCestWrapper() === 'cest_rerun_whole')
            {
                $this->wrapWholeCest($result, $path, $cestName, $methods, $cestGroups, $expectedGroups);
            }
            else
            {
                $this->wrapClusterCest($result, $path, $cestName, $methods, $cestGroups, $expectedGroups);
            }
        }

        return $result;
    }

    protected function notInExpectedGroups(Set $actualGroups, Set $expectedGroups) : bool
    {
        return !$expectedGroups->isEmpty() && $actualGroups->intersect($expectedGroups)->isEmpty();
    }

    protected function wrapTests(Queue $queue, string $path, string $cestName, array $methods, Set $cestGroups, Set $expectedGroups) : void
    {
        foreach ($methods as $methodName => $testData)
        {
            $actualGroups = $cestGroups->union(new Set($testData['groups'] ?? []));

            $this->wrapTest($queue, $path, $cestName, $methodName, $actualGroups, $expectedGroups);
        }
    }

    protected function wrapTest(Queue $queue, string $path, string $cestName, string $methodName, Set $actualGroups, Set $expectedGroups) : void
    {
        $testName = "$cestName:$methodName";

        if ($this->skipTests->matchesTest($testName)
            && !($this->immuneTests->matchesPath($path) || $this->immuneTests->matchesCest($cestName) || $this->immuneTests->matchesTest($testName)))
        {
            $this->log->debug($testName . ' -> skipped (in skip_tests)');
            return;
        }

        if ($this->notInExpectedGroups($actualGroups, $expectedGroups)
            && !($this->immuneTests->matchesPath($path) || $this->immuneTests->matchesCest($cestName) || $this->immuneTests->matchesTest($testName)))
        {
            $this->log->debug($testName . ' -> skipped (not in a group)');
            return;
        }

        $test = $this->wrapperFactory->getTestWrapper($cestName, $methodName);

        $queue->push($test);
    }

    protected function wrapWholeCest(Queue $queue, string $path, string $cestName, array $methods, Set $cestGroups, Set $expectedGroups) : void
    {
        $actualGroups = $cestGroups->union($this->collectMethodGroups($methods));

        if ($this->notInExpectedGroups($actualGroups, $expectedGroups)
            && !($this->immuneTests->matchesPath($path) || $this->immuneTests->matchesCest($cestName)))
        {
            $this->log->debug($cestName . ' -> skipped (not in a group)');
            return;
        }

        $test = $this->wrapperFactory->getCestWrapper($cestName, $actualGroups, $expectedGroups);

        $queue->push($test);
    }

    protected function wrapClusterCest(Queue $queue, string $path, string $cestName, array $methods, Set $cestGroups, Set $expectedGroups) : void
    {
        $actualGroups = $cestGroups->union($this->collectMethodGroups($methods));

        if ($this->notInExpectedGroups($actualGroups, $expectedGroups)
            && !($this->immuneTests->matchesPath($path) || $this->immuneTests->matchesCest($cestName)))
        {
            $this->log->debug($cestName . ' -> skipped (not in a group)');
            return;
        }

        $test = $this->wrapperFactory->getClusterCestWrapper($cestName, $actualGroups, $expectedGroups);

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
            if ($test->matches($this->onlyTests) || $test->matches($this->immuneTests))
            {
                $result->push($test);
            }
        }

        return $result;
    }
}
