<?php

use Ecotone\Messaging\Config\ModulePackageList;
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
        'namespaces' => ['Monorepo\\ExampleApp\\Common'],
        'defaultErrorChannel' => 'errorChannel',
        'failFast' => false,
        'skippedModulePackageNames' => ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]),
    ]);

    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Monorepo\\ExampleApp\\Common\\', '%kernel.project_dir%/../Common/');

    $services->set(Configuration::class)->public();
    $services->get(OrderController::class)->public();

    $services->set(UserRepository::class)->factory([service(Configuration::class), 'userRepository']);
    $services->set(ProductRepository::class)->factory([service(Configuration::class), 'productRepository']);
    $services->get(AuthenticationService::class)->factory([service(Configuration::class), 'authentication']);
};