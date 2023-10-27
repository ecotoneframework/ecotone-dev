<?php

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Monorepo\ExampleApp\Common\Domain\Clock;
use Monorepo\ExampleApp\Common\Domain\Notification\NotificationSender;
use Monorepo\ExampleApp\Common\Domain\Notification\NotificationSubscriber;
use Monorepo\ExampleApp\Common\Domain\Product\ProductRepository;
use Monorepo\ExampleApp\Common\Domain\Shipping\ShippingService;
use Monorepo\ExampleApp\Common\Domain\Shipping\ShippingSubscriber;
use Monorepo\ExampleApp\Common\Domain\User\UserRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Authentication\AuthenticationService;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\Infrastructure\Converter\UuidConverter;
use Monorepo\ExampleApp\Common\Infrastructure\ErrorChannelService;
use Monorepo\ExampleApp\Common\Infrastructure\InMemory\InMemoryOrderRepository;
use Monorepo\ExampleApp\Common\Infrastructure\InMemory\InMemoryProductRepository;
use Monorepo\ExampleApp\Common\Infrastructure\InMemory\InMemoryUserRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Output;
use Monorepo\ExampleApp\Common\Infrastructure\StubNotificationSender;
use Monorepo\ExampleApp\Common\Infrastructure\StubShippingService;
use Monorepo\ExampleApp\Common\Infrastructure\SystemClock;

return function (bool $useCachedVersion = true): ConfiguredMessagingSystem {
    $output = new Output();

    $configuration = new Configuration();
    $stubShippingService = new StubShippingService($output, $configuration);
    $stubNotificationSender = new StubNotificationSender($output, $configuration);

    $services = [
        Configuration::class => $configuration,
        NotificationSender::class => $stubNotificationSender,
        ShippingService::class => $stubShippingService,
        Clock::class => new SystemClock(),
        AuthenticationService::class => $configuration->authentication(),
        UserRepository::class => $configuration->userRepository(),
        ProductRepository::class => $configuration->productRepository(),
        ShippingSubscriber::class => new ShippingSubscriber($stubShippingService),
        NotificationSubscriber::class => new NotificationSubscriber($stubNotificationSender),
        Output::class => $output,
        ErrorChannelService::class => new ErrorChannelService(),
        UuidConverter::class => new UuidConverter(),
        InMemoryOrderRepository::class => new InMemoryOrderRepository(),
        InMemoryProductRepository::class => $configuration->productRepository(),
        InMemoryUserRepository::class => $configuration->userRepository(),
    ];
    return EcotoneLite::bootstrap(
        containerOrAvailableServices: $services,
        configuration: ServiceConfiguration::createWithDefaults()
            ->doNotLoadCatalog()
            ->withCacheDirectoryPath(__DIR__ . "/var/cache")
            ->withFailFast(false)
            ->withDefaultErrorChannel('errorChannel')
            ->withNamespaces(['Monorepo\\ExampleApp\\Common\\'])
            ->withSkippedModulePackageNames(\json_decode(\getenv('APP_SKIPPED_PACKAGES'), true)),
        useCachedVersion: $useCachedVersion,
        pathToRootCatalog: __DIR__.'/../Common',
    );
};
