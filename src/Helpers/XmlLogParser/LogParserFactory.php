<?php

namespace Paracestamol\Helpers\XmlLogParser;

class LogParserFactory
{
    public function get(string $xmlFile) : XmlLogParser
    {
        return new XmlLogParser($xmlFile);
    }
}
