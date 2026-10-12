<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('ecotone', [
        'licenceKey' => getenv('ECOTONE_LICENSE_KEY') ?: null,
    ]);

    $services = $containerConfigurator->services();

    $services->load('App\\MultiTenant\\', '%kernel.project_dir%/src/')
        ->autowire()
        ->autoconfigure();
};