<?php

namespace Paracestamol\Helpers\XmlLogParser\Records;

use Ds\Hashable;
use Paracestamol\Exceptions\LogParserException;
use Paracestamol\Helpers\TextHelper;

class TestCaseRecord implements Hashable
{
    protected string $name;
    protected float  $time;
    protected string $status;
    protected string $message = '';

    public const STATUS_PASS    = 'pass';
    public const STATUS_FAIL    = 'failure';
    public const STATUS_ERROR   = 'error';
    public const STATUS_SKIPPED = 'skipped';

    public function __construct(\XMLReader $reader)
    {
        $name = $reader->getAttribute('name');

        if ($name === null)
        {
            throw new LogParserException('Can\'t parse a test name from the record: ' . $reader->readString());
        }

        $this->name = $name;
        $this->time = (float) ($reader->getAttribute('time') ?? '0.0');

        $this->parseStatus($reader);
    }

    protected function parseStatus(\XMLReader $reader) : void
    {
        $node = $reader->expand();

        if ($node === false)
        {
            throw new LogParserException('Can\'t parse the record: ' . $reader->readString());
        }

        if (!$node->hasChildNodes())
        {
            $this->status = static::STATUS_PASS;
            return;
        }

        /** @var \DOMNode $childNode */
        foreach ($node->childNodes as $childNode)
        {
            if ($childNode->nodeName === static::STATUS_FAIL)
            {
                $this->status = static::STATUS_FAIL;
                $this->message = $this->parseMessage($childNode->textContent);
                return;
            }

            if ($childNode->nodeName === static::STATUS_ERROR)
            {
                $this->status = static::STATUS_ERROR;
                $this->message = $this->parseMessage($childNode->textContent);
                return;
            }

            if ($childNode->nodeName === static::STATUS_SKIPPED)
            {
                $this->status = static::STATUS_SKIPPED;
                return;
            }
        }

        throw new LogParserException('Can\'t parse a test status from the record: ' . $reader->readString());
    }

    protected function parseMessage(string $message) : string
    {
        if (empty($message))
        {
            return '';
        }

        $lines = explode(PHP_EOL, $message);

        array_shift($lines);

        $result = [];

        foreach ($lines as $line)
        {
            $trimmedLine = TextHelper::strip($line);

            if ($trimmedLine === '')
            {
                break;
            }

            $result []= $trimmedLine;
        }

        return implode(' ', $result);
    }

    public function getName() : string
    {
        return $this->name;
    }

    public function getTime() : float
    {
        return $this->time;
    }

    public function getStatus() : string
    {
        return $this->status;
    }

    public function getMessage() : string
    {
        return $this->message;
    }

    public function isPassed() : bool
    {
        return $this->getStatus() === static::STATUS_PASS;
    }

    public function isSkipped() : bool
    {
        return $this->getStatus() === static::STATUS_SKIPPED;
    }

    public function __toString() : string
    {
        return $this->name;
    }

    public function hash()
    {
        return (string) $this;
    }

    public function equals($obj) : bool
    {
        if (!is_callable([$obj, 'hash']))
        {
            return false;
        }

        return $this->hash() === $obj->hash();
    }
}
