<?php

use DI\ContainerBuilder;
use Symfony\Component\Console\Application;

require_once __DIR__ . '/autoload.php';

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

$builder = new ContainerBuilder();
$builder->addDefinitions(__DIR__ . '/DIConfig.php');
$container = $builder->build();

/** @var Application $app */
$app = $container->get(Application::class);

return $app->run();