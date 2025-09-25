<?php

use Ecotone\Messaging\Config\ModulePackageList;
use Enqueue\Dbal\DbalConnectionFactory;
use Monorepo\ExampleApp\Common\Domain\Product\ProductRepository;
use Monorepo\ExampleApp\Common\Domain\User\UserRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Authentication\AuthenticationService;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('ecotone', [
        'defaultSerializationMediaType' => 'application/json',
        'loadSrcNamespaces' => false,
        'namespaces' => ['Monorepo\\ExampleAppEventSourcing\\Common\\', 'Monorepo\\ExampleAppEventSourcing\\ProophProjection\\'],
        'defaultErrorChannel' => 'errorChannel',
        'failFast' => false,
        'skippedModulePackageNames' => \json_decode(\getenv('APP_SKIPPED_PACKAGES'), true),
    ]);

    $services = $containerConfigurator->services();

    $services->load('Monorepo\\ExampleAppEventSourcing\\Common\\', '%kernel.project_dir%/../Common/')
        ->autowire()
        ->autoconfigure();

    $services->load('Monorepo\\ExampleAppEventSourcing\\ProophProjection\\', '%kernel.project_dir%/../ProophProjection/')
        ->autowire()
        ->autoconfigure();

    $services->set(DbalConnectionFactory::class)->args([
        env('DATABASE_DSN', 'pgsql://ecotone:secret@localhost:5432/ecotone')
    ]);
};