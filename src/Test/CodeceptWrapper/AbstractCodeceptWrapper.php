<?php

namespace Paracetamol\Test\CodeceptWrapper;

use Paracetamol\Exceptions\UsageException;
use Paracetamol\Helpers\JsonLogParser\JsonLogParser;
use Paracetamol\Helpers\JsonLogParser\JsonLogParserFactory;
use Paracetamol\Helpers\TestNameParts;
use Paracetamol\Log\Log;
use Paracetamol\Settings\SettingsRun;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

abstract class AbstractCodeceptWrapper implements ICodeceptWrapper
{
    protected Log                  $log;
    protected SettingsRun          $settings;
    protected JsonLogParserFactory $jsonLogParserFactory;

    protected string               $cestName;
    protected string               $methodName;
    protected string               $testName;

    protected string               $jsonLogName;
    private   string               $jsonLogFullpath;

    protected ?JsonLogParser       $parsedJsonLog     = null;
    private   ?int                 $startTime         = null;
    private   ?int                 $expectedDuration  = null;
    private   ?int                 $actualDuration    = null;
    private   bool                 $timedOut          = false;
    private   ?Process             $proc              = null;
    protected string               $statusDescription = '';

    public function __construct(Log $log, SettingsRun $settings, JsonLogParserFactory $jsonLogParserFactory, string $cestName, string $methodName)
    {
        $this->log                  = $log;
        $this->settings             = $settings;
        $this->jsonLogParserFactory = $jsonLogParserFactory;

        $this->cestName             = $cestName;
        $this->methodName           = $methodName;
        $this->testName             = "$cestName:$methodName";

        $this->initJsonLog();

        $this->log->debug($this->testName);
    }

    protected function initJsonLog()
    {
        $this->jsonLogName     = sha1($this->testName . bin2hex(random_bytes(8))) . '.json';
        $this->jsonLogFullpath = $this->settings->getRunOutputPath() . DIRECTORY_SEPARATOR . $this->jsonLogName;
    }

    abstract protected function getCmd() : array;

    protected function reset()
    {
        $this->startTime         = null;
        $this->actualDuration    = null;
        $this->timedOut          = false;
        $this->proc              = null;
        $this->statusDescription = '';
        $this->parsedJsonLog     = null;

        $this->tryDeleteJsonLog();
    }

    public function start()
    {
        $this->reset();

        $cmd = $this->getCmd();

        $this->proc = new Process($cmd);
        $this->proc->setTimeout(null);
        $this->proc->setIdleTimeout($this->settings->getIdleTimeoutSec() !== -1 ? $this->settings->getIdleTimeoutSec() : null);
        $this->proc->start();

        $this->startTime = time();

        $this->log->debug($this->proc->getCommandLine());
    }

    private function parseJsonLog()
    {
        if (!file_exists($this->jsonLogFullpath))
        {
            throw new UsageException('File is not exist: ' . $this->jsonLogFullpath);
        }

        $contents = file_get_contents($this->jsonLogFullpath);

        if (empty($contents)) // Test failed to start
        {
            return;
        }

        $this->parsedJsonLog = $this->jsonLogParserFactory->get($contents);
    }

    public function isRunning() : bool
    {
        if (!isset($this->proc))
        {
            return false;
        }

        if ($this->isTimedOut())
        {
            $result = false;
        }
        else
        {
            $result = $this->proc->isRunning();
        }

        if (!$result)
        {
            $this->parseJsonLog();
            $this->tryDeleteJsonLog();
        }

        return $result;
    }

    public function isTimedOut() : bool
    {
        try
        {
            $this->proc->checkTimeout();
        }
        catch (ProcessTimedOutException $e)
        {
            $this->timedOut = true;
        }

        return $this->timedOut;
    }

    public function isSuccessful() : bool
    {
        return $this->proc->isSuccessful();
    }

    public function getOutput() : string
    {
        return $this->proc->getOutput();
    }

    public function getErrorOutput() : string
    {
        return $this->proc->getErrorOutput();
    }

    abstract public function getStatusDescription() : string;

    public function matches(TestNameParts $nameParts) : bool
    {
        return $this->getMatch($nameParts) !== null;
    }

    public function getMatch(TestNameParts $nameParts) : ?string
    {
        if ($nameParts->getTests()->contains((string) $this))
        {
            return $this;
        }

        if ($nameParts->getCests()->contains($this->cestName))
        {
            return $this->cestName;
        }

        $path = dirname($this->cestName);

        if ($nameParts->getPaths()->contains($path))
        {
            return $path;
        }

        return null;
    }

    public function getExpectedDuration() : ?int
    {
        return $this->expectedDuration;
    }

    public function setExpectedDuration(int $expectedDuration) : void
    {
        $this->expectedDuration = $expectedDuration;
    }

    public function getActualDuration() : ?int
    {
        if ($this->actualDuration === null && $this->startTime !== null)
        {
            $this->actualDuration = $this->proc->getLastOutputTime() - $this->startTime;
        }

        return $this->actualDuration;
    }

    private function tryDeleteJsonLog()
    {
        @unlink($this->jsonLogFullpath);
    }

    //========================================================================

    public function __destruct()
    {
        $this->tryDeleteJsonLog();
    }

    public function __toString()
    {
        return $this->testName;
    }

    public function hash()
    {
        return $this->testName;
    }

    public function equals($obj) : bool
    {
        if (!is_callable([$obj, 'hash']))
        {
            return false;
        }

        return $this->hash() === $obj->hash();
    }

    public function __clone()
    {
        $this->initJsonLog();

        $this->reset();
    }
}
