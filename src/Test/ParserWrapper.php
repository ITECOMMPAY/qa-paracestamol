<?php

namespace Paracestamol\Test;

use Paracestamol\Exceptions\LoaderException;
use Paracestamol\Log\Log;
use Paracestamol\Settings\SettingsRun;
use Symfony\Component\Process\Process;

class ParserWrapper
{
    protected Log            $log;
    protected SettingsRun    $settings;

    protected string $parsedTestsResultName = '';

    public function __construct(Log $log, SettingsRun $settings)
    {
        $this->log = $log;
        $this->settings = $settings;
    }

    public function parse() : array
    {
        $this->log->veryVerbose('Parsing tests using a separate process');

        $this->runParser();

        return $this->decodeParsedTestsResult();
    }

    protected function runParser()
    {
        $this->log->debug(' -> creating parser process');

        $cmd = $this->getParserCmd();

        $this->proc = new Process($cmd);
        $this->proc->setTimeout(null);
        $this->proc->setIdleTimeout(null);

        $this->log->debug($this->proc->getCommandLine());

        $this->proc->run();

        if (!$this->proc->isSuccessful())
        {
            $error = $this->resolveParserError($this->proc);

            throw new LoaderException('Tests parser failed with: ' . $error);
        }
    }

    protected function decodeParsedTestsResult() : array
    {
        $this->log->debug('decoding parser result');

        if (!file_exists($this->getParsedTestsResultName()))
        {
            $this->log->debug('parser result doesn\'t exist');
            return [];
        }

        $contents = file_get_contents($this->getParsedTestsResultName());

        if ($contents === false)
        {
            $this->log->debug('can\'t read the parser result');
            return [];
        }

        $json = json_decode($contents, true);

        if ($json === null)
        {
            $this->log->debug('can\'t decode the parser result as JSON');
            return [];
        }

        @unlink($this->getParsedTestsResultName());

        return $json;
    }

    protected function resolveParserError(Process $proc) : string
    {
        $errorOutput = empty($proc->getErrorOutput()) ? $proc->getOutput() : $proc->getErrorOutput();

        $result = $this->decodeParsedTestsResult();

        if (empty($result['data']))
        {
            return $errorOutput;
        }

        return json_encode($result['data']);
    }

    protected function findParacestamolBinary() : string
    {
        $path = __DIR__ . '/../../paracestamol';

        $result = realpath($path);

        if ($result === false)
        {
            throw new LoaderException('Can\'t find the paracestamol binary by the path: ' . $path);
        }

        $this->log->debug('Found the paracestamol binary: ' . $result);

        return $result;
    }

    protected function getParsedTestsResultName() : string
    {
        if (empty($this->parsedTestsResultName))
        {
            $this->parsedTestsResultName = $this->settings->getRunOutputPath() . DIRECTORY_SEPARATOR . $this->settings->getRunId() . '_parsed_tests.json';

            $this->log->debug('Parsing result will be placed at: ' . $this->parsedTestsResultName);
        }

        return $this->parsedTestsResultName;
    }

    protected function getParserCmd() : array
    {
        $paracestamolBin = $this->findParacestamolBinary();

        $suite = $this->settings->getSuite();

        $codeceptionConfigPath = $this->settings->getTestProjectPath();

        $outputFile = $this->getParsedTestsResultName();

        $runOptions = [
            '-vv',
            '--cache_tests', $this->settings->isCacheTests() ? 'true' : 'false',
        ];

        if (!empty($this->settings->getStoreCacheIn()))
        {
            $runOptions []= '--store_cache_in';
            $runOptions []= $this->settings->getStoreCacheIn();
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

        if ($this->settings->isNoMemoryLimit())
        {
            $runOptions []= '--no_memory_limit';
            $runOptions []= 'true';
        }

        return ['php', $paracestamolBin, 'parse', $suite, $codeceptionConfigPath, $outputFile, ...$runOptions];
    }
}
