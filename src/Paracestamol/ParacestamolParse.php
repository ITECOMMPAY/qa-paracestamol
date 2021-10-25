<?php

namespace Paracestamol\Paracestamol;

use Paracestamol\Exceptions\ParserException;
use Paracestamol\Log\Log;
use Paracestamol\Settings\SettingsParse;
use Paracestamol\Test\Parser;

class ParacestamolParse
{
    protected Log           $log;
    protected SettingsParse $settings;
    protected Parser        $parser;

    public function __construct(Log $log, SettingsParse $settings, Parser $parser)
    {
        $this->log = $log;
        $this->settings = $settings;
        $this->parser = $parser;
    }

    public function execute() : void
    {
        $this->log->section('Parsing');

        $cacheFileName = $this->getTestCacheFileName();
        $previousData = $this->tryLoadTestCache($cacheFileName);

        $result = $this->parser->parseTests($this->settings->getTestsPath(), $previousData);
        $encodedResult = json_encode($result, JSON_THROW_ON_ERROR);

        $this->trySaveTestCache($cacheFileName, $encodedResult);

        $this->trySaveResult($encodedResult);
    }

    protected function getTestCacheFileName() : string
    {
        $prefix = sha1(json_encode($this->settings->getSuiteConfig()));

        if (!empty($this->settings->getStoreCacheIn()))
        {
            $path = $this->settings->getStoreCacheIn();
        }
        else
        {
            $path = dirname($this->settings->getResultFile());
        }


        return $path . DIRECTORY_SEPARATOR . $prefix . '_parsed_tests_cache.json';
    }

    protected function tryLoadTestCache(string $filename) : array
    {
        if (!$this->settings->isCacheTests())
        {
            return [];
        }

        $this->log->verbose('Trying to load a test cache from: ' . $filename);

        if (!file_exists($filename))
        {
            $this->log->veryVerbose('No previous result exists');

            return [];
        }

        $file = fopen($filename,"rb");

        if ($file === false || !flock($file,LOCK_SH))
        {
            $this->log->veryVerbose('Can\'t lock the test cache file for reading');

            return [];
        }

        $contents = file_get_contents($filename);

        flock($file, LOCK_UN);

        if ($contents === false)
        {
            $this->log->veryVerbose('Can\'t read the test cache file contents');

            return [];
        }

        $decodedResult = json_decode($contents, true);

        if ($decodedResult === null)
        {
            $this->log->veryVerbose('Can\'t decode the test cache file contents as JSON');

            return [];
        }

        if (!isset($decodedResult['data']))
        {
            $this->log->veryVerbose('The test cache doesn\'t contain a valid data');

            return [];
        }

        return $decodedResult['data'];
    }

    protected function trySaveTestCache(string $filename, string $encodedResult)
    {
        if (!$this->settings->isCacheTests())
        {
            return;
        }

        $this->log->verbose('Trying to save the test cache');

        if (file_put_contents($filename, $encodedResult, LOCK_EX) === false)
        {
            $this->log->note('Can\'t save the test cache');
        }
    }

    protected function trySaveResult(string $encodedResult)
    {
        if (file_put_contents($this->settings->getResultFile(), $encodedResult) === false)
        {
            throw new ParserException('Can\'t save the current result');
        }
    }
}
