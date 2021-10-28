<?php

namespace Paracestamol\Test\CodeceptWrapper\Wrapper;

use Ds\Set;
use Ds\Vector;
use Paracestamol\Helpers\JsonLogParser\JsonLogParserFactory;
use Paracestamol\Helpers\JsonLogParser\Records\TestRecord;
use Paracestamol\Helpers\TextHelper;
use Paracestamol\Log\Log;
use Paracestamol\Module\ParacestamolHelper;
use Paracestamol\Settings\SettingsRun;
use Paracestamol\Test\CodeceptWrapper\AbstractCodeceptWrapper;
use Paracestamol\Test\Delayer;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class CestWrapper extends AbstractCodeceptWrapper
{
    protected Delayer $delayer;

    protected string $groupsRunString;

    protected Vector $passedTestRecords;
    protected Vector $failedTestRecords;

    protected ?bool $failFast = null;
    protected ?InputStream $inputStream = null;
    protected bool $waitsUntilTestStartAllowed = false;

    public function __construct(Log $log, SettingsRun $settings, JsonLogParserFactory $jsonLogParserFactory, Delayer $delayer, string $cestName, Set $actualGroups, ?Set $expectedGroups = null)
    {
        parent::__construct($log, $settings, $jsonLogParserFactory, $cestName, $this->determineName($actualGroups, $expectedGroups));

        $this->passedTestRecords = new Vector();
        $this->failedTestRecords = new Vector();

        $this->delayer = $delayer;
    }

    protected function determineName(Set $actualGroups, ?Set $expectedGroups) : string
    {
        if ($expectedGroups === null)
        {
            return '()';
        }

        $commonGroups = $actualGroups->intersect($expectedGroups)->toArray();
        asort($commonGroups, SORT_STRING);

        $this->groupsRunString = implode(',', $commonGroups);

        return '(' . $this->groupsRunString . ')';
    }

    protected function reset() : void
    {
        parent::reset();

        $this->passedTestRecords          = new Vector();
        $this->failedTestRecords          = new Vector();
        $this->inputStream                = null;
        $this->waitsUntilTestStartAllowed = false;
    }

    protected function getCmd() : array
    {
        $codeceptionBin = $this->settings->getCodeceptionBinPath();

        $suite = $this->settings->getSuite();

        $runOptions = [
            '--config', $this->settings->getCodeceptionConfigPath(),
            '--json',   $this->jsonLogName,
            '-o',       'paths: output: ' . $this->settings->getRunOutputPath(),
            '--no-colors',
            '--no-interaction',
            '--no-rebuild',
        ];

        if ($this->isFailFast())
        {
            $runOptions []= '--fail-fast';
        }

        if (!empty($this->groupsRunString))
        {
            $runOptions []= '-g';
            $runOptions []= $this->groupsRunString;
        }

        if (!empty($this->settings->getEnv()))
        {
            $runOptions []= '--env';
            $runOptions []= $this->settings->getEnvAsString();
        }

        if ($this->settings->isParacestamolModuleEnabled())
        {
            $runOptions []= '-o';
            $runOptions []= 'modules: config: ' . $this->settings->getParacestamolModuleName() . ': pause_before_test: true';
        }

        if (!empty($this->settings->getOverride()))
        {
            $runOptions []= '-o';
            $runOptions []= $this->settings->getOverrideAsString();
        }

        return ['php', $codeceptionBin, 'run', $suite, $this->cestName, ...$runOptions];
    }

    protected function configureProcess(Process $proc) : void
    {
        if (!$this->settings->isParacestamolModuleEnabled())
        {
            return;
        }

        $this->inputStream = new InputStream();

        $proc->setInput($this->inputStream);
    }

    protected function isFailFast() : bool
    {
        return $this->failFast ?? $this->settings->isWholeCestFailFast();
    }

    public function setFailFast(bool $value) : void
    {
        $this->failFast = $value;
    }

    public function isRunning() : bool
    {
        $result = parent::isRunning();

        if ($result)
        {
            $this->allowTestStart();
        }
        else
        {
            $this->parseFailedTests();
        }

        return $result;
    }

    protected function allowTestStart() : void
    {
        if (!$this->settings->isParacestamolModuleEnabled())
        {
            return;
        }

        if ($this->settings->getDelayMsec() === 0)
        {
            return;
        }

        if (!$this->waitsUntilTestStartAllowed)
        {
            $prompt = mb_substr($this->getIncrementalOutput(), -strlen(ParacestamolHelper::ALLOW_TEST_START_PROMPT));

            if ($prompt !== ParacestamolHelper::ALLOW_TEST_START_PROMPT)
            {
                return;
            }
        }

        if (!$this->delayer->allowsTestStart())
        {
            $this->waitsUntilTestStartAllowed = true;
            return;
        }

        $this->waitsUntilTestStartAllowed = false;
        $this->inputStream->write("Y\n");
    }

    protected function parseFailedTests() : void
    {
        if ($this->parsedJsonLog === null)
        {
            $this->log->note($this . ' -> broken or timed out' . PHP_EOL . $this->getErrorOutput());
            return;
        }

        /** @var TestRecord $testRecord */
        foreach ($this->parsedJsonLog->getTests() as $testRecord)
        {
            if ($testRecord->isPassed())
            {
                $this->passedTestRecords->push($testRecord);
            }
            else
            {
                $this->failedTestRecords->push($testRecord);
            }
        }
    }

    public function isSuccessful() : bool
    {
        if ($this->parsedJsonLog === null || !parent::isSuccessful())
        {
            return false;
        }

        return $this->failedTestRecords->isEmpty();
    }

    public function isMarkedSkipped() : bool
    {
        return false;
    }

    public function getStatusDescription() : string
    {
        if ($this->statusDescription === '' && $this->isTimedOut())
        {
            $this->statusDescription = $this->cestName . ': TIMEOUT';
        }

        if ($this->statusDescription === '' && $this->parsedJsonLog === null)
        {
            $this->statusDescription = $this->cestName . ': BROKEN ' . TextHelper::strip($this->getErrorOutput());
        }

        if ($this->statusDescription === '' && !$this->failedTestRecords->isEmpty() && $this->isFailFast())
        {
            $testRecord = $this->failedTestRecords->first();
            $message = $testRecord->getMessagePlain();
            $this->statusDescription = "$this->cestName:{$testRecord->getMethod()} (and following)" . ': ' . $message;
        }

        if ($this->statusDescription === '' && !$this->failedTestRecords->isEmpty())
        {
            $messages = [];

            /** @var TestRecord $testRecord */
            foreach ($this->failedTestRecords as $testRecord)
            {
                $message = $testRecord->getMessagePlain();

                if ($message === '')
                {
                    continue;
                }

                $messages []= "$this->cestName:{$testRecord->getMethod()}" . ': ' . $message;
            }

            $this->statusDescription = implode(PHP_EOL, $messages);
        }

        return $this->statusDescription;
    }

    public function getPassedTestRecords() : Vector
    {
        return $this->passedTestRecords;
    }

    public function getFailedTestRecords() : Vector
    {
        return $this->failedTestRecords;
    }

    public function hasPassedTestsThisRun() : bool
    {
        return !$this->passedTestRecords->isEmpty();
    }
}
