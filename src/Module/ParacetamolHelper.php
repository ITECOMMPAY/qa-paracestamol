<?php

namespace Paracestamol\Module;

use Codeception\Module as CodeceptionModule;
use Codeception\TestInterface;

class ParacestamolHelper extends CodeceptionModule
{
    public const ALLOW_TEST_START_PROMPT = 'Paracestamol allows start? [Y]';

    protected $config = [
        'pause_before_test' => false
    ];

    public function _before(TestInterface $test)
    {
        if (!$this->config['pause_before_test'])
        {
            return;
        }

        echo PHP_EOL;
        echo static::ALLOW_TEST_START_PROMPT;

        fscanf(STDIN, "%s", $a);
    }
}
