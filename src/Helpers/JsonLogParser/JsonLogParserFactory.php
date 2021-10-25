<?php

namespace Paracestamol\Helpers\JsonLogParser;

class JsonLogParserFactory
{
    public function get(string $jsonString) : JsonLogParser
    {
        return new JsonLogParser($jsonString);
    }
}
