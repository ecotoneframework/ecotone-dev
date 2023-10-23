<?php

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Monorepo\ExampleApp\Common\Domain\Clock;
use Monorepo\ExampleApp\Common\Domain\Notification\NotificationSender;
use Monorepo\ExampleApp\Common\Domain\Notification\NotificationSubscriber;
use Monorepo\ExampleApp\Common\Domain\Order\Order;
use Monorepo\ExampleApp\Common\Domain\Order\OrderRepository;
use Monorepo\ExampleApp\Common\Domain\Product\Product;
use Monorepo\ExampleApp\Common\Domain\Product\ProductRepository;
use Monorepo\ExampleApp\Common\Domain\Shipping\ShippingService;
use Monorepo\ExampleApp\Common\Domain\Shipping\ShippingSubscriber;
use Monorepo\ExampleApp\Common\Domain\User\User;
use Monorepo\ExampleApp\Common\Domain\User\UserRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Authentication\AuthenticationService;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\Infrastructure\Converter\UuidConverter;
use Monorepo\ExampleApp\Common\Infrastructure\ErrorChannelService;
use Monorepo\ExampleApp\Common\Infrastructure\InMemory\InMemoryOrderRepository;
use Monorepo\ExampleApp\Common\Infrastructure\InMemory\InMemoryProductRepository;
use Monorepo\ExampleApp\Common\Infrastructure\InMemory\InMemoryUserRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Messaging\MessageChannelConfiguration;
use Monorepo\ExampleApp\Common\Infrastructure\Output;
use Monorepo\ExampleApp\Common\Infrastructure\StubNotificationSender;
use Monorepo\ExampleApp\Common\Infrastructure\StubShippingService;
use Monorepo\ExampleApp\Common\Infrastructure\SystemClock;
use Monorepo\ExampleApp\CommonEventSourcing\PriceChangeOverTimeProjection;

return function (bool $useCachedVersion = true): ConfiguredMessagingSystem {
    $output = new Output();

    $configuration = new Configuration();
    $inMemoryOrderRepository = new InMemoryOrderRepository();
    $stubShippingService = new StubShippingService($output, $configuration);
    $stubNotificationSender = new StubNotificationSender($output, $configuration);
    $namespaces = \json_decode(\getenv('APP_NAMESPACES_TO_LOAD'), true);

    $classesToRegister = [];
    $services = [];
    if (in_array('Monorepo\ExampleApp\Common', $namespaces)) {
        $services = array_merge([
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
        ], $services);
        $classesToRegister = array_merge(
            [
                MessageChannelConfiguration::class,
                Order::class,
                Product::class,
                User::class,
            ],
            $classesToRegister
        );
    }
    if (in_array('Monorepo\ExampleApp\CommonEventSourcing', $namespaces)) {
        $services = [
            PriceChangeOverTimeProjection::class => new PriceChangeOverTimeProjection()
        ];
        $classesToRegister[] = \Monorepo\ExampleApp\CommonEventSourcing\Product::class;
    }

    return EcotoneLite::bootstrap(
        array_merge(array_keys($services), $classesToRegister),
        containerOrAvailableServices: $services,
        configuration: ServiceConfiguration::createWithDefaults()
            ->doNotLoadCatalog()
            ->withCacheDirectoryPath(__DIR__ . "/var/cache")
            ->withFailFast(false)
            ->withDefaultErrorChannel('errorChannel')
            ->withNamespaces($namespaces)
            ->withSkippedModulePackageNames(\json_decode(\getenv('APP_SKIPPED_PACKAGES'), true)),
        useCachedVersion: $useCachedVersion,
        pathToRootCatalog: __DIR__.'/../Common',
    );
};
