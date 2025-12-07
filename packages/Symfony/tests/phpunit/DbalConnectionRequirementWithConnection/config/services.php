<?php

use Ecotone\Messaging\Config\ModulePackageList;
use Enqueue\Dbal\DbalConnectionFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('ecotone', [
        'skippedModulePackageNames' => ModulePackageList::allPackagesExcept([
            ModulePackageList::SYMFONY_PACKAGE,
            ModulePackageList::DBAL_PACKAGE,
        ]),
    ]);

    $services = $containerConfigurator->services();

    $services->load('Symfony\\App\\DbalConnectionRequirementWithConnection\\', '%kernel.project_dir%/src/')
        ->autowire()
        ->autoconfigure();

    $services->set(DbalConnectionFactory::class)->args([
        env('DATABASE_DSN', 'pgsql://ecotone:secret@localhost:5432/ecotone')
    ]);
};

