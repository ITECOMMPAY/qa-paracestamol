<?php

namespace Paracestamol\Helpers;

class TextHelper
{
    public static function strip(string $text) : string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
