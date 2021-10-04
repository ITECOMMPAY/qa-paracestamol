<?php

namespace Paracetamol\Test\CodeceptWrapper\Wrapper;

use Paracetamol\Helpers\JsonLogParser\Records\TestRecord;
use Paracetamol\Test\CodeceptWrapper\AbstractCodeceptWrapper;

class TestWrapper extends AbstractCodeceptWrapper
{
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
        if ($this->parsedJsonLog === null || !parent::isSuccessful())
        {
            return false;
        }

        /** @var TestRecord $testRecord */
        $testRecord = $this->parsedJsonLog->getTests()->first()->value;
        return $testRecord->isPassed();
    }

    public function isMarkedSkipped() : bool
    {
        if ($this->parsedJsonLog === null)
        {
            return false;
        }

        /** @var TestRecord $testRecord */
        $testRecord = $this->parsedJsonLog->getTests()->first()->value;
        return $testRecord->isSkipped();
    }

    public function getStatusDescription() : string
    {
        if ($this->statusDescription === '' && $this->parsedJsonLog !== null)
        {
            /** @var TestRecord $testRecord */
            $testRecord = $this->parsedJsonLog->getTests()->first()->value;
            $this->statusDescription = $testRecord->getMessagePlain();
        }

        return $this->statusDescription;
    }
}
