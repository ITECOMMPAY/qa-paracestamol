<?php


namespace Paracestamol\Log;


use Paracestamol\Exceptions\UsageException;
use Paracestamol\Settings\SettingsRun;
use Symfony\Component\Console\Style\OutputStyle;

/**
 * Class Log
 *
 * @method void title(string $message)
 * @method void section(string $message)
 * @method void listing(string $message)
 * @method void text(string $message)
 * @method void success(string $message)
 * @method void error(string $message)
 * @method void warning(string $message)
 * @method void note(string $message)
 * @method void caution(string $message)
 * @method void newLine(int $count = 1)
 *
 * @package Paracestamol\Log
 */
class Log
{
    protected SettingsRun $settings;
    protected OutputStyle $style;

    public function __construct(SettingsRun $settings)
    {
        $this->settings = $settings;
    }

    public function setStyle(OutputStyle $style)
    {
        $this->style = $style;
    }

    public function __call(string $name, array $arguments)
    {
        if (isset($this->style) && method_exists($this->style, $name))
        {
            $this->style->$name(...$arguments);
            return;
        }

        if ($name === 'newLine')
        {
            echo PHP_EOL;
            return;
        }

        if (count($arguments) === 1 && !is_object($arguments[0]))
        {
            echo is_array($arguments[0]) ? json_encode($arguments[0]) : $arguments[0] . PHP_EOL;
            return;
        }

        throw new UsageException("Logger doesn't have a method with name: $name - that can take arguments: " . json_encode($arguments));
    }

    protected int  $progressMax = -1;

    public function progressStart(int $max = 0) : void
    {
        if (!isset($this->style))
        {
            return;
        }

        $this->progressMax = $max;

        if ($this->style->getVerbosity() !== OutputStyle::VERBOSITY_NORMAL)
        {
            return;
        }

        $this->style->progressStart($max);
    }

    public function progressAdvance(int $step = 1) : void
    {
        if (!isset($this->style))
        {
            return;
        }

        $this->progressMax -= $step;

        if ($this->style->getVerbosity() !== OutputStyle::VERBOSITY_NORMAL)
        {
            if ($this->progressMax >= 50 && $this->progressMax % 50 === 0)
            {
                $this->verbose("======= ($this->progressMax tests remain) =======");
            }

            return;
        }

        $this->style->progressAdvance($step);
    }

    public function progressFinish() : void
    {
        if (!isset($this->style))
        {
            return;
        }

        $this->progressMax = -1;

        if ($this->style->getVerbosity() !== OutputStyle::VERBOSITY_NORMAL)
        {
            return;
        }

        $this->style->progressFinish();
    }

    public function normal($message) : void
    {
        $this->writeln($message, OutputStyle::VERBOSITY_NORMAL);
    }

    public function verbose($message) : void
    {
        $this->writeln($message, OutputStyle::VERBOSITY_VERBOSE);
    }

    public function veryVerbose($message) : void
    {
        $this->writeln($message, OutputStyle::VERBOSITY_VERY_VERBOSE);
    }

    public function debug($message) : void
    {
        $this->writeln($message, OutputStyle::VERBOSITY_DEBUG);
    }

    protected function writeln($message, int $verbosity) : void
    {
        if (!isset($this->style))
        {
            if (is_string($message))
            {
                echo $message . PHP_EOL;
            }

            return;
        }

        if ($verbosity > $this->style->getVerbosity())
        {
            return;
        }

        if (is_array($message))
        {
            $message = json_encode($message);
        }

        $this->style->writeln(sprintf(' %s', $message), OutputStyle::OUTPUT_NORMAL|$verbosity);
    }
}
