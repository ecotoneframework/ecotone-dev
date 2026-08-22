<?php

use Ecotone\Messaging\Config\ModulePackageList;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {

    $containerConfigurator->parameters()->set('app.customer.activate_on_register', true);
    $containerConfigurator->parameters()->set('app.multiplier', 10);
    $containerConfigurator->parameters()->set('app.env_multiplier', '%env(int:APP_MULTIPLIER)%');

    $containerConfigurator->extension('ecotone', [
        'modulePackages' => [ModulePackageList::SYMFONY_PACKAGE,
            ModulePackageList::DBAL_PACKAGE,],
    ]);

    $services = $containerConfigurator->services();

    $services->load('Symfony\\App\\SingleTenant\\', '%kernel.project_dir%/src/')
        ->autowire()
        ->autoconfigure();
};
