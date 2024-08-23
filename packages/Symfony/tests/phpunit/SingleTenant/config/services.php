<?php

use Ecotone\Messaging\Config\ModulePackageList;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {

    $containerConfigurator->parameters()->set('app.customer.activate_on_register', true);

    $containerConfigurator->extension('ecotone', [
        'skippedModulePackageNames' => ModulePackageList::allPackagesExcept([
            ModulePackageList::SYMFONY_PACKAGE,
            ModulePackageList::DBAL_PACKAGE,
            ModulePackageList::ASYNCHRONOUS_PACKAGE,
        ]),
    ]);

    $services = $containerConfigurator->services();

    $services->load('Symfony\\App\\SingleTenant\\', '%kernel.project_dir%/src/')
        ->autowire()
        ->autoconfigure();
};
