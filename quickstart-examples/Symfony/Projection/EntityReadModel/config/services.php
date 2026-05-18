<?php

/*
 * licence Apache-2.0
 */

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->load('App\\', '%kernel.project_dir%/src/')
        ->autowire()
        ->autoconfigure();
};
