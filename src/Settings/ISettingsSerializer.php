<?php

namespace Paracestamol\Settings;

use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Serializer;

interface ISettingsSerializer
{
    public function getSerializer() : Serializer;

    public function getNameConverter() : NameConverterInterface;
}
