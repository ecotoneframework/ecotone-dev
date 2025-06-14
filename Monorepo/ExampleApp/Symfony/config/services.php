<?php

use Monorepo\ExampleApp\Common\Domain\Product\ProductRepository;
use Monorepo\ExampleApp\Common\Domain\User\UserRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Authentication\AuthenticationService;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Test\Ecotone\OpenTelemetry\Integration\TracingTestCase;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('ecotone', [
        'loadSrcNamespaces' => false,
        'namespaces' => ['Monorepo\\ExampleApp\\Common\\'],
        'defaultErrorChannel' => 'errorChannel',
        'failFast' => false,
        'skippedModulePackageNames' => \json_decode(\getenv('APP_SKIPPED_PACKAGES'), true),
    ]);

    $services = $containerConfigurator->services();

    $services->load('Monorepo\\ExampleApp\\Common\\', '%kernel.project_dir%/../Common/')
        ->autowire()
        ->autoconfigure();

    $services->set(Configuration::class)->public();
    $services->get(OrderController::class)->public();

    $services->set(UserRepository::class)->factory([service(Configuration::class), 'userRepository']);
    $services->set(ProductRepository::class)->factory([service(Configuration::class), 'productRepository']);
    $services->get(AuthenticationService::class)->factory([service(Configuration::class), 'authentication']);

    $services->set(InMemoryExporter::class, InMemoryExporter::class)
            ->public();
    $services->set(TracerProviderInterface::class)
        ->factory([TracingTestCase::class, 'prepareTracer'])
        ->args([service(InMemoryExporter::class)])
        ->public();
};