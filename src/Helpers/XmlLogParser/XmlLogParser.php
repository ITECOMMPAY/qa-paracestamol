<?php

namespace Paracestamol\Helpers\XmlLogParser;

use Ds\Map;
use Paracestamol\Exceptions\LogParserException;
use Paracestamol\Helpers\XmlLogParser\Records\TestCaseRecord;

class XmlLogParser
{
    protected Map $tests;

    public function __construct(string $xmlFile)
    {
        $this->tests = new Map();

        $this->parseLog($xmlFile);
    }

    protected function parseLog(string $xmlFile)
    {
        $stat = stat($xmlFile);

        if ($stat['size'] === 0)
        {
            throw new LogParserException("Log is empty: $xmlFile");
        }

        $reader = \XMLReader::open($xmlFile);

        if (!($reader instanceof \XMLReader))
        {
            throw new LogParserException("Can't open log file: $xmlFile");
        }

        while ($reader->read())
        {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'testcase')
            {
                continue;
            }

            $this->parseTestCaseRecord($reader);
        }

        $reader->close();
    }

    protected function parseTestCaseRecord(\XMLReader $reader)
    {
        $testCaseRecord = new TestCaseRecord($reader);
        $this->tests->put((string) $testCaseRecord, $testCaseRecord);
    }

    public function getTestCases() : Map
    {
        return $this->tests;
    }
}
