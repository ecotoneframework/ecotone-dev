<?php

use Ecotone\Messaging\Config\ModulePackageList;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $ecotoneConfiguration = [
        'modulePackages' => [ModulePackageList::SYMFONY_PACKAGE,
            ModulePackageList::DBAL_PACKAGE,],
    ];

    if (getenv('SYMFONY_LICENCE_KEY') !== false && getenv('SYMFONY_LICENCE_KEY') !== '') {
        $ecotoneConfiguration['licenceKey'] = '%env(SYMFONY_LICENCE_KEY)%';
    }

    $containerConfigurator->extension('ecotone', $ecotoneConfiguration);

    $services = $containerConfigurator->services();

    $services->load('Symfony\\App\\MultiTenant\\', '%kernel.project_dir%/src/')
        ->autowire()
        ->autoconfigure();
};
