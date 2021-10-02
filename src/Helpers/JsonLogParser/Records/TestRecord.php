<?php

namespace Paracetamol\Helpers\JsonLogParser\Records;

use Ds\Hashable;

class TestRecord implements Hashable
{
    protected string $test;
    protected string $status;
    protected float  $time;
    protected string $message;

    protected string $method = '';

    public const STATUS_PASS    = 'pass';
    public const STATUS_FAIL    = 'fail';
    public const STATUS_ERROR   = 'error';
    public const STATUS_SKIPPED = 'skipped';

    public function __construct(array $record)
    {
        $this->test    = $record['test'][1] ?? '';
        $this->status  = $record['status']  ?? '';
        $this->time    = $record['time']    ?? 0.0;
        $this->message = $record['message'] ?? '';

        if ($this->status === static::STATUS_ERROR)
        {
            $this->checkIfSkipped($record);
        }
    }

    /**
     * @return string - like "NotDividableCest: Test02"
     */
    public function getTest() : string
    {
        return $this->test;
    }

    public function getMethod() : string
    {
        if ($this->method === '')
        {
            $parts = explode(' ', $this->getTest());
            $this->method = end($parts);
        }

        return $this->method;
    }

    public function checkIfSkipped(array $record)
    {
        $trace = $record['trace'] ?? [];

        $step = reset($trace);

        $function = $step['function'] ?? '';

        if ($function === 'markTestSkipped')
        {
            $this->status = static::STATUS_SKIPPED;
        }
    }

    public function getStatus() : string
    {
        return $this->status;
    }

    public function getTime() : float
    {
        return $this->time;
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
        return $this->test;
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
