<?php

namespace Paracetamol\Test;

use Paracetamol\Exceptions\LoaderException;
use Paracetamol\Log\Log;
use Paracetamol\Settings\SettingsRun;
use Symfony\Component\Finder\Finder;
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

        if ($this->settings->isReduceParserMemoryUsage())
        {
            return $this->runParserReducedMemory();
        }

        return $this->runParserSimple($this->getParsedTestsResultName());
    }

    protected function runParserSimple(string $resultFile, string $cestName = '') : array
    {
        $cmd = $this->getParserCmd($resultFile, $cestName);

        $proc = $this->getProcess($cmd);

        $proc->run();

        if (!$proc->isSuccessful())
        {
            $error = $this->resolveParserError($proc, $resultFile);

            throw new LoaderException('Tests parser failed with: ' . $error);
        }

        return $this->decodeParsedTestsResult($resultFile)['data']['cests'] ?? [];
    }

    protected function runParserReducedMemory() : array
    {
        $testsDir = $this->settings->getTestsPath();

        $files = Finder::create()
                       ->files()
                       ->name('*Cest.php')
                       ->in($testsDir)
                       ->followLinks()
                        ;

        $result = [];

        /** @var \Symfony\Component\Finder\SplFileInfo $file */
        foreach ($files as $file)
        {

            $cestName = $file->getRelativePathname();
            $suffix = '__parsed_test_' . $file->getFilenameWithoutExtension();

            $resultFile = $this->getParsedTestsResultName($suffix);

            $result []= $this->runParserSimple($resultFile, $cestName);
        }

        return array_merge(...$result);
    }

    protected function getProcess(array $cmd) : Process
    {
        $this->log->debug(' -> creating parser process');

        $proc = new Process($cmd);
        $proc->setTimeout(null);
        $proc->setIdleTimeout(null);

        $this->log->debug($proc->getCommandLine());

        return $proc;
    }

    protected function decodeParsedTestsResult(string $resultFile) : array
    {
        $this->log->debug('decoding parser result');

        if (!file_exists($resultFile))
        {
            $this->log->debug('parser result doesn\'t exist');
            return [];
        }

        $contents = file_get_contents($resultFile);

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

        @unlink($resultFile);

        return $json;
    }

    protected function resolveParserError(Process $proc, string $resultFile) : string
    {
        $errorOutput = empty($proc->getErrorOutput()) ? $proc->getOutput() : $proc->getErrorOutput();

        $result = $this->decodeParsedTestsResult($resultFile);

        if (empty($result['data']))
        {
            return $errorOutput;
        }

        return json_encode($result['data']);
    }

    protected function findParacetamolBinary() : string
    {
        $path = __DIR__ . '/../../paracetamol';

        $result = realpath($path);

        if ($result === false)
        {
            throw new LoaderException('Can\'t find the paracetamol binary by the path: ' . $path);
        }

        $this->log->debug('Found the paracetamol binary: ' . $result);

        return $result;
    }

    protected function getParsedTestsResultName(string $suffix = '_parsed_tests') : string
    {
        if (empty($this->parsedTestsResultName))
        {
            $this->parsedTestsResultName = $this->settings->getRunOutputPath() . DIRECTORY_SEPARATOR . $this->settings->getRunId() . $suffix . '.json';

            $this->log->debug('Parsing result will be placed at: ' . $this->parsedTestsResultName);
        }

        return $this->parsedTestsResultName;
    }

    protected function getParserCmd(string $resultFile, string $cestName = '') : array
    {
        $paracetamolBin = $this->findParacetamolBinary();

        $suite = $this->settings->getSuite();

        $codeceptionConfigPath = $this->settings->getTestProjectPath();

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

        if (!empty($cestName))
        {
            $runOptions []= '--only_cest';
            $runOptions []= $cestName;
        }

        return ['php', $paracetamolBin, 'parse', $suite, $codeceptionConfigPath, $resultFile, ...$runOptions];
    }
}
