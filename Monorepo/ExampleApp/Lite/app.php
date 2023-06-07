<?php

use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Monorepo\ExampleApp\Common\Domain\Clock;
use Monorepo\ExampleApp\Common\Domain\Notification\NotificationSender;
use Monorepo\ExampleApp\Common\Domain\Order\OrderRepository;
use Monorepo\ExampleApp\Common\Domain\Product\ProductRepository;
use Monorepo\ExampleApp\Common\Domain\Shipping\ShippingService;
use Monorepo\ExampleApp\Common\Domain\User\UserRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Authentication\AuthenticationService;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\Infrastructure\InMemory\InMemoryOrderRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Output;
use Monorepo\ExampleApp\Common\Infrastructure\StubNotificationSender;
use Monorepo\ExampleApp\Common\Infrastructure\StubShippingService;
use Monorepo\ExampleApp\Common\Infrastructure\SystemClock;

return function (): ConfiguredMessagingSystem {
    $output = new Output();

    $configuration = new Configuration();

    $classesToRegister = [
        Configuration::class => $configuration,
        NotificationSender::class => new StubNotificationSender($output),
        ShippingService::class => new StubShippingService($output),
        Clock::class => new SystemClock(),
        OrderRepository::class => new InMemoryOrderRepository(),
        AuthenticationService::class => $configuration->authentication(),
        UserRepository::class => $configuration->userRepository(),
        ProductRepository::class => $configuration->productRepository(),
    ];

    return EcotoneLiteApplication::bootstrap(
        serviceConfiguration: ServiceConfiguration::createWithDefaults()
            ->doNotLoadCatalog()
            ->withCacheDirectoryPath(__DIR__ . "/var/cache")
            ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
            ->withNamespaces(['Monorepo\\ExampleApp\\Common\\']),
        cacheConfiguration: true,
        pathToRootCatalog: __DIR__.'/../Common',
        classesToRegister: $classesToRegister,
    );
};
