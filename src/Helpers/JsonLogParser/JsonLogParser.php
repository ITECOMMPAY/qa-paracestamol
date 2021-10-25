<?php

namespace Paracestamol\Helpers\JsonLogParser;

use Ds\Map;
use Paracestamol\Helpers\JsonLogParser\Records\TestRecord;

class JsonLogParser
{
    protected Map $tests;

    public function __construct(string $jsonString)
    {
        $log = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);

        $this->tests = new Map();

        $this->parseLog($log);
    }

    protected function parseLog(array $log)
    {
        foreach ($log as $record)
        {
            $event = $record['event'] ?? '';

            if ($event === 'test')
            {
                $this->parseTestRecord($record);
                continue;
            }
        }
    }

    protected function parseTestRecord(array $record)
    {
        $testRecord = new TestRecord($record);
        $this->tests->put((string) $testRecord, $testRecord);
    }

    public function getTests() : Map
    {
        return $this->tests;
    }
}
