<?php

namespace Paracetamol\Test\CodeceptWrapper\Wrapper;

use Ds\Set;
use Ds\Vector;
use Paracetamol\Helpers\JsonLogParser\JsonLogParserFactory;
use Paracetamol\Helpers\JsonLogParser\Records\TestRecord;
use Paracetamol\Log\Log;
use Paracetamol\Settings\SettingsRun;
use Paracetamol\Test\CodeceptWrapper\AbstractCodeceptWrapper;

class CestWrapper extends AbstractCodeceptWrapper
{
    protected string $groupsRunString;

    protected Vector $passedTestRecords;
    protected Vector $failedTestRecords;

    public function __construct(Log $log, SettingsRun $settings, JsonLogParserFactory $jsonLogParserFactory, string $cestName, Set $actualGroups, ?Set $expectedGroups = null)
    {
        parent::__construct($log, $settings, $jsonLogParserFactory, $cestName, $this->determineName($actualGroups, $expectedGroups));

        $this->passedTestRecords = new Vector();
        $this->failedTestRecords = new Vector();
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

        $this->passedTestRecords = new Vector();
        $this->failedTestRecords = new Vector();
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
        ];

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

        if (!empty($this->settings->getOverride()))
        {
            $runOptions []= '-o';
            $runOptions []= $this->settings->getOverrideAsString();
        }

        return ['php', $codeceptionBin, 'run', $suite, $this->cestName, ...$runOptions];
    }

    public function isRunning() : bool
    {
        $result = parent::isRunning();

        if (!$result)
        {
            $this->parseFailedTests();
        }

        return $result;
    }

    protected function parseFailedTests() : void
    {
        if ($this->parsedJsonLog === null)
        {
            $this->log->note($this . ' -> failed to start' . PHP_EOL . $this->getErrorOutput());
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
}
