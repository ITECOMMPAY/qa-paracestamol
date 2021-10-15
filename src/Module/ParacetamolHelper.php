<?php

namespace Paracetamol\Module;

use Codeception\Module as CodeceptionModule;
use Codeception\TestInterface;

class ParacetamolHelper extends CodeceptionModule
{
    public const ALLOW_TEST_START_PROMPT = 'Paracetamol allows start? [Y]';

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
