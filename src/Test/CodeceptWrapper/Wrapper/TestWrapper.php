<?php

namespace Paracestamol\Test\CodeceptWrapper\Wrapper;

use Paracestamol\Helpers\XmlLogParser\Records\TestCaseRecord;
use Paracestamol\Helpers\TextHelper;
use Paracestamol\Test\CodeceptWrapper\AbstractCodeceptWrapper;

class TestWrapper extends AbstractCodeceptWrapper
{
    protected function getCmd() : array
    {
        $codeceptionBin = $this->settings->getCodeceptionBinPath();

        $suite = $this->settings->getSuite();

        $runOptions = [
            '--config', $this->settings->getCodeceptionConfigPath(),
            '--xml',    $this->xmlLogName,
            '-o',       'paths: output: ' . $this->settings->getRunOutputPath(),
            '--no-colors',
            '--no-interaction',
            '--no-rebuild',
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

        return ['php', $codeceptionBin, 'run', $suite, "{$this->cestName}:^{$this->methodName}$", ...$runOptions];
    }

    public function isSuccessful() : bool
    {
        if ($this->parsedXmlLog === null || $this->parsedXmlLog->getTestCases()->isEmpty() || !parent::isSuccessful())
        {
            return false;
        }

        /** @var TestCaseRecord $testCaseRecord */
        $testCaseRecord = $this->parsedXmlLog->getTestCases()->first()->value;
        return $testCaseRecord->isPassed();
    }

    public function isMarkedSkipped() : bool
    {
        if ($this->parsedXmlLog === null || $this->parsedXmlLog->getTestCases()->isEmpty())
        {
            return false;
        }

        /** @var TestCaseRecord $testCaseRecord */
        $testCaseRecord = $this->parsedXmlLog->getTestCases()->first()->value;
        return $testCaseRecord->isSkipped();
    }

    public function getStatusDescription() : string
    {
        if ($this->statusDescription === '')
        {
            if ($this->parsedXmlLog !== null && !$this->parsedXmlLog->getTestCases()->isEmpty())
            {
                /** @var TestCaseRecord $testCaseRecord */
                $testCaseRecord = $this->parsedXmlLog->getTestCases()->first()->value;
                $this->statusDescription = $testCaseRecord->getMessage();
            }
            else
            {
                $this->statusDescription = TextHelper::strip($this->getErrorOutput());
            }
        }

        return $this->statusDescription;
    }
}
