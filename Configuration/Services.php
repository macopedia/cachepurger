<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
            ->autoconfigure()
            ->autowire()
            ->private();

    $services->load('Macopedia\\CachePurger\\', '../Classes/*');
};
