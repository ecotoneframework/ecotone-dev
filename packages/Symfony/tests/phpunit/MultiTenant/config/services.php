<?php

use Ecotone\Dbal\DbalConnection;
use Ecotone\Messaging\Config\ModulePackageList;
use Monorepo\ExampleApp\Common\Domain\Product\ProductRepository;
use Monorepo\ExampleApp\Common\Domain\User\UserRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Authentication\AuthenticationService;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Test\Ecotone\OpenTelemetry\Integration\TracingTest;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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