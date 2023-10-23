<?php

use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Monorepo\ExampleApp\Common\Domain\Clock;
use Monorepo\ExampleApp\Common\Domain\Notification\NotificationSender;
use Monorepo\ExampleApp\Common\Domain\Notification\NotificationSubscriber;
use Monorepo\ExampleApp\Common\Domain\Order\OrderRepository;
use Monorepo\ExampleApp\Common\Domain\Product\ProductRepository;
use Monorepo\ExampleApp\Common\Domain\Shipping\ShippingService;
use Monorepo\ExampleApp\Common\Domain\Shipping\ShippingSubscriber;
use Monorepo\ExampleApp\Common\Domain\User\UserRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Authentication\AuthenticationService;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\Infrastructure\ErrorChannelService;
use Monorepo\ExampleApp\Common\Infrastructure\InMemory\InMemoryOrderRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Output;
use Monorepo\ExampleApp\Common\Infrastructure\StubNotificationSender;
use Monorepo\ExampleApp\Common\Infrastructure\StubShippingService;
use Monorepo\ExampleApp\Common\Infrastructure\SystemClock;

return function (bool $useCachedVersion = true): ConfiguredMessagingSystem {
    $output = new Output();

    $configuration = new Configuration();
    $stubNotificationSender = new StubNotificationSender($output, $configuration);
    $stubShippingService = new StubShippingService($output, $configuration);
    $namespaces = \json_decode(\getenv('APP_NAMESPACES_TO_LOAD'), true);

    $classesToRegister = [];
    if (in_array('Monorepo\ExampleApp\Common', $namespaces)) {
        $classesToRegister = array_merge([
            Configuration::class => $configuration,
            NotificationSender::class => $stubNotificationSender,
            ShippingService::class => $stubShippingService,
            Clock::class => new SystemClock(),
            OrderRepository::class => new InMemoryOrderRepository(),
            AuthenticationService::class => $configuration->authentication(),
            UserRepository::class => $configuration->userRepository(),
            ProductRepository::class => $configuration->productRepository(),
            ShippingSubscriber::class => new ShippingSubscriber($stubShippingService),
            NotificationSubscriber::class => new NotificationSubscriber($stubNotificationSender),
            Output::class => $output,
            ErrorChannelService::class => new ErrorChannelService()
        ], $classesToRegister);
    }

    return EcotoneLiteApplication::bootstrap(
        serviceConfiguration: ServiceConfiguration::createWithDefaults()
            ->doNotLoadCatalog()
            ->withCacheDirectoryPath(__DIR__ . "/var/cache")
            ->withFailFast(false)
            ->withDefaultErrorChannel('errorChannel')
            ->withNamespaces($namespaces)
            ->withSkippedModulePackageNames(\json_decode(\getenv('APP_SKIPPED_PACKAGES'), true)),
        cacheConfiguration: $useCachedVersion,
        pathToRootCatalog: __DIR__.'/../Common',
        classesToRegister: $classesToRegister,
    );
};
