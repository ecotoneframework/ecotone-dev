<?php

use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Config\ModulePackageList;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('ecotone', [
        'modulePackages' => [
            ModulePackageList::SYMFONY_PACKAGE,
            ModulePackageList::DBAL_PACKAGE,
            ModulePackageList::EVENT_SOURCING_PACKAGE,
        ],
        'licenceKey' => '%env(SYMFONY_LICENCE_KEY)%',
    ]);

    $services = $containerConfigurator->services();

    $services->load('Symfony\\App\\DcbSmoke\\', '%kernel.project_dir%/src/')
        ->exclude('%kernel.project_dir%/src/Kernel.php')
        ->autowire()
        ->autoconfigure();

    $services->set(DbalConnectionFactory::class)->args([
        '%env(DATABASE_DSN)%',
    ])->public();
};
