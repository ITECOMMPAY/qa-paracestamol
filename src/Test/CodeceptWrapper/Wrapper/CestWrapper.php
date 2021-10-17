<?php

namespace Paracetamol\Test\CodeceptWrapper\Wrapper;

use Ds\Set;
use Ds\Vector;
use Paracetamol\Helpers\JsonLogParser\JsonLogParserFactory;
use Paracetamol\Helpers\JsonLogParser\Records\TestRecord;
use Paracetamol\Helpers\TextHelper;
use Paracetamol\Log\Log;
use Paracetamol\Module\ParacetamolHelper;
use Paracetamol\Settings\SettingsRun;
use Paracetamol\Test\CodeceptWrapper\AbstractCodeceptWrapper;
use Paracetamol\Test\Delayer;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class CestWrapper extends AbstractCodeceptWrapper
{
    protected Delayer $delayer;

    protected string $groupsRunString;

    protected Vector $passedTestRecords;
    protected Vector $failedTestRecords;

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

        if ($this->settings->isParacetamolModuleEnabled())
        {
            $runOptions []= '-o';
            $runOptions []= 'modules: config: ' . $this->settings->getParacetamolModuleName() . ': pause_before_test: true';
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
        if (!$this->settings->isParacetamolModuleEnabled())
        {
            return;
        }

        $this->inputStream = new InputStream();

        $proc->setInput($this->inputStream);
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
        if (!$this->settings->isParacetamolModuleEnabled())
        {
            return;
        }

        if ($this->settings->getDelayMsec() === 0)
        {
            return;
        }

        if (!$this->waitsUntilTestStartAllowed)
        {
            $prompt = mb_substr($this->getIncrementalOutput(), -strlen(ParacetamolHelper::ALLOW_TEST_START_PROMPT));

            if ($prompt !== ParacetamolHelper::ALLOW_TEST_START_PROMPT)
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
