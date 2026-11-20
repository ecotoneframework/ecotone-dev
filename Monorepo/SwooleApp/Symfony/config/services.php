<?php

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Test\LicenceTesting;
use Monorepo\SwooleApp\Symfony\ProbeController;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', ['secret' => 'swoole', 'http_method_override' => false]);
    $containerConfigurator->extension('ecotone', [
        'licenceKey' => LicenceTesting::VALID_LICENCE,
        'loadSrcNamespaces' => false,
        'namespaces' => ['Monorepo\\SwooleApp\\Common\\'],
        'enabledModulePackageNames' => [
            ModulePackageList::SYMFONY_PACKAGE,
            ModulePackageList::DBAL_PACKAGE,
            ModulePackageList::EVENT_SOURCING_PACKAGE,
            ModulePackageList::JMS_CONVERTER_PACKAGE,
        ],
    ]);

    $services = $containerConfigurator->services();
    $services->set('logger', NullLogger::class);
    $services->load('Monorepo\\SwooleApp\\Common\\', '%kernel.project_dir%/../Common/')->autowire()->autoconfigure();
    $services->set(ProbeController::class)->autowire()->public()->tag('controller.service_arguments');
    $services->set(DbalConnectionFactory::class)->args([env('DATABASE_DSN')]);
};
