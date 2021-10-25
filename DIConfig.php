<?php

use Paracestamol\Command\Parse;
use Paracestamol\Command\Run;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;

return [
    Application::class => function (ContainerInterface $c)
    {
        $app = new Application();
        $app->add($c->get(Run::class));
        $app->add($c->get(Parse::class));
        return $app;
    }
];
