<?php

use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Monorepo\ExampleApp\Common\Domain\Clock;
use Monorepo\ExampleApp\Common\Domain\Notification\NotificationSender;
use Monorepo\ExampleApp\Common\Domain\Product\ProductRepository;
use Monorepo\ExampleApp\Common\Domain\Shipping\ShippingService;
use Monorepo\ExampleApp\Common\Domain\User\UserRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Authentication\AuthenticationService;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\Infrastructure\Output;
use Monorepo\ExampleApp\Common\Infrastructure\StubNotificationSender;
use Monorepo\ExampleApp\Common\Infrastructure\StubShippingService;
use Monorepo\ExampleApp\Common\Infrastructure\SystemClock;

return function (bool $useCachedVersion = true): ConfiguredMessagingSystem {
    $output = new Output();

    $configuration = new Configuration();

    $classesToRegister = [
        Configuration::class => $configuration,
        NotificationSender::class => new StubNotificationSender($output),
        ShippingService::class => new StubShippingService($output),
        Clock::class => new SystemClock(),
        AuthenticationService::class => $configuration->authentication(),
        UserRepository::class => $configuration->userRepository(),
        ProductRepository::class => $configuration->productRepository(),
    ];

    return EcotoneLiteApplication::bootstrap(
        serviceConfiguration: ServiceConfiguration::createWithDefaults()
            ->doNotLoadCatalog()
            ->withCacheDirectoryPath(__DIR__ . "/var/cache")
            ->withFailFast(false)
            ->withSkippedModulePackageNames(array_merge(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]), [ModulePackageList::TEST_PACKAGE]))
            ->withNamespaces(['Monorepo\\ExampleApp\\Common\\']),
        cacheConfiguration: $useCachedVersion,
        pathToRootCatalog: __DIR__.'/../Common',
        classesToRegister: $classesToRegister,
    );
};
