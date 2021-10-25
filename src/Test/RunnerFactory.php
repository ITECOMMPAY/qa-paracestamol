<?php


namespace Paracestamol\Test;


use Ds\Queue;
use Paracestamol\Log\Log;

class RunnerFactory
{
    protected Delayer $delayer;
    protected Log     $log;

    public function __construct(Log $log, Delayer $delayer)
    {
        $this->log = $log;
        $this->delayer = $delayer;
    }

    public function get(Queue $queue) : Runner
    {
        return new Runner($this->log, $this->delayer, $queue);
    }
}
