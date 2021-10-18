<?php

namespace Paracetamol\Helpers;

use Paracetamol\Settings\ICodeceptionHelperSettings;
use Paracetamol\Settings\ISettingsSerializer;
use Symfony\Component\Console\Input\InputInterface;

trait CommandParamsToSettingsSaver
{
    abstract protected function getSettings() : ICodeceptionHelperSettings;

    abstract protected function getSettingsSerializer() : ISettingsSerializer;

    protected function nameToSettingsSetter(string $name) : string
    {
        $camelCaseName = ucfirst($this->getSettingsSerializer()->getNameConverter()->denormalize($name));
        return "set{$camelCaseName}";
    }

    protected function saveArgument(InputInterface $input, string $argument)
    {
        $value = $input->getArgument($argument);

        $setter = $this->nameToSettingsSetter($argument);

        $this->getSettings()->$setter($value);
    }

    protected function overrideSettings(InputInterface $input, string $option)
    {
        $value = $input->getOption($option);

        if ($value === null)
        {
            return;
        }

        if (is_array($value) && empty($value))
        {
            return;
        }

        if (is_array($value) && count($value) === 1)
        {
            if ($value[0] === "'[]'" || $value[0] === '"[]"' || $value[0] === '[]')
            {
                $value = [];
            }
        }

        if (is_string($value))
        {
            $trimmedValue = strtolower(trim($value));

            if ($trimmedValue === 'true')
            {
                $value = true;
            }
            elseif ($trimmedValue === 'false')
            {
                $value = false;
            }
        }

        $setter = $this->nameToSettingsSetter($option);

        $this->getSettings()->$setter($value);
    }
}
