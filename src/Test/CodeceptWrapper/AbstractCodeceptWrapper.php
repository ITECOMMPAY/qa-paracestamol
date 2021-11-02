<?php

namespace Paracestamol\Test\CodeceptWrapper;

use Paracestamol\Exceptions\LogParserException;
use Paracestamol\Helpers\TestNameParts;
use Paracestamol\Helpers\XmlLogParser\LogParserFactory;
use Paracestamol\Helpers\XmlLogParser\XmlLogParser;
use Paracestamol\Log\Log;
use Paracestamol\Settings\SettingsRun;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

abstract class AbstractCodeceptWrapper implements ICodeceptWrapper
{
    protected Log                  $log;
    protected SettingsRun          $settings;
    protected LogParserFactory     $logParserFactory;

    protected string               $cestName;
    protected string               $methodName;
    protected string               $testName;

    protected string               $xmlLogName;
    private   string               $xmlLogFullpath;

    protected ?XmlLogParser        $parsedXmlLog      = null;
    private   ?int                 $startTime         = null;
    private   ?int                 $expectedDuration  = null;
    private   ?int                 $actualDuration    = null;
    private   bool                 $timedOut          = false;
    private   ?Process             $proc              = null;
    protected string               $statusDescription = '';

    public function __construct(Log $log, SettingsRun $settings, LogParserFactory $logParserFactory, string $cestName, string $methodName)
    {
        $this->log                  = $log;
        $this->settings             = $settings;
        $this->logParserFactory     = $logParserFactory;

        $this->cestName             = $cestName;
        $this->methodName           = mb_strtolower($methodName);
        $this->testName             = "$cestName:$methodName";

        $this->initXmlLog();

        $this->log->debug($this->testName);
    }

    protected function initXmlLog()
    {
        $this->xmlLogName     = sha1($this->testName . bin2hex(random_bytes(8))) . '.xml';
        $this->xmlLogFullpath = $this->settings->getRunOutputPath() . DIRECTORY_SEPARATOR . $this->xmlLogName;
    }

    abstract protected function getCmd() : array;

    protected function reset()
    {
        $this->startTime         = null;
        $this->actualDuration    = null;
        $this->timedOut          = false;
        $this->proc              = null;
        $this->statusDescription = '';
        $this->parsedXmlLog     = null;

        $this->tryDeleteXmlLog();
    }

    public function start() : void
    {
        $this->reset();

        $cmd = $this->getCmd();

        $this->proc = new Process($cmd);
        $this->proc->setTimeout(null);
        $this->proc->setIdleTimeout($this->settings->getIdleTimeoutSec() !== -1 ? $this->settings->getIdleTimeoutSec() : null);

        $this->configureProcess($this->proc);

        $this->proc->start();

        $this->startTime = time();

        $this->log->debug($this->proc->getCommandLine());
    }

    protected function configureProcess(Process $proc) : void
    {

    }

    private function parseXmlLog() : void
    {
        if (!file_exists($this->xmlLogFullpath))
        {
            return;  // Test failed to start
        }

        try
        {
            $this->parsedXmlLog = $this->logParserFactory->get($this->xmlLogFullpath);
        }
        catch (LogParserException $e)
        {
            $this->log->note($this->testName . ': ' . $e->getMessage());
            $this->parsedXmlLog = null;
        }
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
            $this->parseXmlLog();
            $this->tryDeleteXmlLog();
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

    protected function getIncrementalOutput() : string
    {
        return $this->proc->getIncrementalOutput();
    }

    abstract public function getStatusDescription() : string;

    public function matches(TestNameParts $nameParts) : bool
    {
        return $this->getMatch($nameParts) !== null;
    }

    public function getMatch(TestNameParts $nameParts) : ?string
    {
        if ($nameParts->matchesTest($this->cestName, $this->methodName))
        {
            return $this;
        }

        if ($nameParts->matchesCest($this->cestName))
        {
            return $this->cestName;
        }

        $path = dirname($this->cestName);

        if ($nameParts->matchesPath($path))
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

    private function tryDeleteXmlLog()
    {
        @unlink($this->xmlLogFullpath);
    }

    //========================================================================

    public function __destruct()
    {
        $this->tryDeleteXmlLog();
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
        $this->initXmlLog();

        $this->reset();
    }
}
