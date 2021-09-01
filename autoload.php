<?php

if (file_exists(__DIR__ . '/vendor/autoload.php'))
{
    // for phar
    require_once __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../autoload.php'))
{
    //for composer
    require_once __DIR__ . '/../../autoload.php';
}
