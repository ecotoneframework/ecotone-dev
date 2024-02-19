<?php

use Ecotone\Messaging\Config\ModulePackageList;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('ecotone', [
        'skippedModulePackageNames' => ModulePackageList::allPackagesExcept([
            ModulePackageList::SYMFONY_PACKAGE,
            ModulePackageList::DBAL_PACKAGE,
            ModulePackageList::ASYNCHRONOUS_PACKAGE,
        ]),
    ]);

    $services = $containerConfigurator->services();

    $services->load('Symfony\\App\\MultiTenant\\', '%kernel.project_dir%/src/')
        ->autowire()
        ->autoconfigure();
};
