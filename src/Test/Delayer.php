<?php


namespace Paracetamol\Test;


use Paracetamol\Settings\SettingsRun;

class Delayer
{
    protected SettingsRun $settings;

    protected float       $nextAllowedTime = -1;

    public function __construct(SettingsRun $settings)
    {
        $this->settings = $settings;
    }

    public function allowsTestStart() : bool
    {
        if ($this->settings->getDelaySeconds() <= 0)
        {
            return true;
        }

        if ($this->nextAllowedTime <= 0)
        {
            $this->nextAllowedTime = microtime(true);
        }

        if (microtime(true) <= $this->nextAllowedTime)
        {
            return false;
        }

        $this->nextAllowedTime = microtime(true) + $this->settings->getDelaySeconds();

        return true;
    }
}
