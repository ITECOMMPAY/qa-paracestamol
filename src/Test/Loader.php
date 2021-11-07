<?php

namespace Paracestamol\Test;

use Ds\Queue;
use Ds\Set;
use Paracestamol\Helpers\TestNameParts;
use Paracestamol\Log\Log;
use Paracestamol\Settings\SettingsRun;
use Paracestamol\Test\CodeceptWrapper\CodeceptWrapperFactory;
use Paracestamol\Test\CodeceptWrapper\ICodeceptWrapper;
use Paracestamol\Test\CodeceptWrapper\Wrapper\CestWrapper;
use Paracestamol\Test\CodeceptWrapper\Wrapper\ClusterCestWrapper;
use Paracestamol\Test\CodeceptWrapper\Wrapper\TestWrapper;

class Loader
{
    protected Log                    $log;
    protected SettingsRun            $settings;
    protected ParserWrapper          $parserWrapper;
    protected CodeceptWrapperFactory $wrapperFactory;

    protected TestNameParts $dividableCests;
    protected TestNameParts $notDividableCestsWhole;
    protected TestNameParts $notDividableCestsOnlyFailed;

    protected TestNameParts $onlyTests;
    protected TestNameParts $skipTests;
    protected TestNameParts $immuneTests;
    protected Set           $expectedGroups;

    public function __construct(Log $log, SettingsRun $settings, ParserWrapper $parserWrapper, CodeceptWrapperFactory $wrapperFactory)
    {
        $this->log            = $log;
        $this->settings       = $settings;
        $this->parserWrapper  = $parserWrapper;
        $this->wrapperFactory = $wrapperFactory;

        $this->dividableCests              = new TestNameParts([]);
        $this->notDividableCestsWhole      = new TestNameParts([]);
        $this->notDividableCestsOnlyFailed = new TestNameParts([]);

        $this->onlyTests      = new TestNameParts([]);
        $this->skipTests      = new TestNameParts([]);
        $this->immuneTests    = new TestNameParts([]);
        $this->expectedGroups = new Set();
    }

    /**
     * @return Queue - [ICodeceptWrapper]
     */
    public function getTests() : Queue
    {
        $this->log->veryVerbose('Searching for tests');

        $this->initializeFilters();

        $result = new Queue();

        foreach ($this->filterTests($this->parseTests()) as $test)
        {
            $result->push($test);
        }

        return $result;
    }

    protected function initializeFilters()
    {
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

        if (!empty($this->settings->getSkipTests()))
        {
            $this->log->veryVerbose('Some tests will be skipped (as per \'skip_tests\' setting)');
            $this->skipTests = new TestNameParts($this->settings->getSkipTests());
        }

        if (!$this->settings->getGroups()->isEmpty())
        {
            $this->log->veryVerbose('Look only for tests with groups: ' . json_encode($this->settings->getGroups()->toArray()));
            $this->expectedGroups = $this->settings->getGroups();
        }

        $markCestsAsDividable = function (TestNameParts $nameParts) : void
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
                $this->dividableCests->getCests()->add($cestName)
                ;
            }
        };

        if (!empty($this->settings->getOnlyTests()))
        {
            $this->log->veryVerbose('Only tests that are mentioned in \'only_tests\' setting will be loaded');
            $this->onlyTests = new TestNameParts($this->settings->getOnlyTests());
            $markCestsAsDividable($this->onlyTests);
        }

        if (!empty($this->settings->getImmuneTests()))
        {
            $this->log->veryVerbose('Some tests will ignore selected groups, \'skip_tests\' and \'only_tests\' settings (as per \'immune_tests\' setting).');
            $this->immuneTests = new TestNameParts($this->settings->getImmuneTests());
            $markCestsAsDividable($this->immuneTests);
        }
    }

    protected function parseTests() : \Generator
    {
        $parsedTestsResult = $this->parserWrapper->parse();

        if (empty($parsedTestsResult['data']['cests']))
        {
            $this->log->debug('No tests found');
            yield from [];
        }

        yield from $this->loadCests($parsedTestsResult['data']['cests']);
    }

    protected function loadCests(array $cests) : \Generator
    {
        foreach ($cests as $cestName => $cestData)
        {
            $path = dirname($cestName);

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

            yield from $this->loadTests($path, $cestName, $cestData);
        }
    }

    protected function loadTests(string $path, string $cestName, array $cestData) : \Generator
    {
        $cestGroups = new Set($cestData['groups'] ?? []);

        $tests = $cestData['tests'];

        foreach ($tests as $testClass => $methods)
        {
            if ($this->dividableCests->matchesCest($cestName) || $this->dividableCests->matchesPath($path))
            {
                yield from $this->wrapTests($path, $cestName, $methods, $cestGroups);

                continue;
            }

            if ($this->notDividableCestsWhole->matchesCest($cestName) || $this->notDividableCestsWhole->matchesPath($path))
            {
                yield $this->wrapWholeCest($path, $cestName, $methods, $cestGroups);

                continue;
            }

            if ($this->notDividableCestsOnlyFailed->matchesCest($cestName) || $this->notDividableCestsOnlyFailed->matchesPath($path))
            {
                yield $this->wrapClusterCest($path, $cestName, $methods, $cestGroups);

                continue;
            }

            if ($this->settings->getCestWrapper() === 'tests')
            {
                yield from $this->wrapTests($path, $cestName, $methods, $cestGroups);

                continue;
            }

            if ($this->settings->getCestWrapper() === 'cest_rerun_whole')
            {
                yield $this->wrapWholeCest($path, $cestName, $methods, $cestGroups);

                continue;
            }

            yield $this->wrapClusterCest($path, $cestName, $methods, $cestGroups);
        }
    }

    protected function wrapTests(string $path, string $cestName, array $methods, Set $cestGroups) : \Generator
    {
        foreach ($methods as $methodName => $testData)
        {
            $actualGroups = $cestGroups->union(new Set($testData['groups'] ?? []));

            yield $this->wrapTest($path, $cestName, $methodName, $actualGroups);
        }
    }

    protected function wrapTest(string $path, string $cestName, string $methodName, Set $actualGroups) : TestWrapper
    {
        return $this->wrapperFactory->getTestWrapper($cestName, $methodName, $actualGroups);
    }

    protected function wrapWholeCest(string $path, string $cestName, array $methods, Set $cestGroups) : CestWrapper
    {
        $actualGroups = $cestGroups->union($this->collectMethodGroups($methods));

        return $this->wrapperFactory->getCestWrapper($cestName, $actualGroups, $this->expectedGroups);
    }

    protected function wrapClusterCest(string $path, string $cestName, array $methods, Set $cestGroups) : ClusterCestWrapper
    {
        $actualGroups = $cestGroups->union($this->collectMethodGroups($methods));

        return $this->wrapperFactory->getClusterCestWrapper($cestName, $actualGroups, $this->expectedGroups);
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

    protected function filterTests(\Generator $parsedTests) : \Generator
    {
        foreach ($parsedTests as $parsedTest)
        {
            if ($this->testIsImmune($parsedTest))
            {
                $this->log->debug($parsedTest . ' -> immune (in immune_tests)');

                yield $parsedTest;
                continue;
            }

            if ($this->testIsInSkipTests($parsedTest))
            {
                $this->log->debug($parsedTest . ' -> skipped (in skip_tests)');

                continue;
            }

            if ($this->testIsNotInGroups($parsedTest))
            {
                $this->log->debug($parsedTest . ' -> skipped (not in expected groups)');

                continue;
            }

            if ($this->testIsNotInOnlyTests($parsedTest))
            {
                $this->log->debug($parsedTest . ' -> skipped (not in only_tests)');

                continue;
            }

            yield $parsedTest;
        }
    }

    protected function testIsImmune(ICodeceptWrapper $test) : bool
    {
        return $test->matches($this->immuneTests);
    }

    protected function testIsInSkipTests(ICodeceptWrapper $test) : bool
    {
        return $test->matches($this->skipTests);
    }

    protected function testIsNotInGroups(ICodeceptWrapper $test) : bool
    {
        if ($this->expectedGroups->isEmpty())
        {
            return false;
        }

        return !$test->inGroups($this->expectedGroups);
    }

    protected function testIsNotInOnlyTests(ICodeceptWrapper $test) : bool
    {
        if ($this->onlyTests->isEmpty())
        {
            return false;
        }

        return !$test->matches($this->onlyTests);
    }
}
