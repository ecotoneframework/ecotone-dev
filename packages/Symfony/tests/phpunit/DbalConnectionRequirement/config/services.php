<?php

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('ecotone', [
        'enabledModulePackageNames' => [ModulePackageList::SYMFONY_PACKAGE,
            ModulePackageList::DBAL_PACKAGE, ],
    ]);

    $services = $containerConfigurator->services();

    $services->set('logger', NullLogger::class);

    $services->load('Symfony\\App\\DbalConnectionRequirement\\', '%kernel.project_dir%/src/')
        ->autowire()
        ->autoconfigure();
};
