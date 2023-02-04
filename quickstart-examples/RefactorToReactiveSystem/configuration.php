<?php

use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Money\Money;
use Ramsey\Uuid\UuidInterface;

function getConfiguredMessagingSystem(mixed $partToRun, UuidInterface $userId, UuidInterface $chairProductId, UuidInterface $tableProductId): ConfiguredMessagingSystem
{
    $stubNotificationSender = sprintf('\App\ReactiveSystem\%s\Infrastructure\StubNotificationSender', $partToRun);
    $stubShippingService = sprintf('\App\ReactiveSystem\%s\Infrastructure\StubShippingService', $partToRun);
    $systemClock = sprintf('\App\ReactiveSystem\%s\Infrastructure\SystemClock', $partToRun);
    $inMemoryOrderRepository = sprintf('\App\ReactiveSystem\%s\Infrastructure\InMemory\InMemoryOrderRepository', $partToRun);
    $authenticationService = sprintf('\App\ReactiveSystem\%s\Infrastructure\Authentication\AuthenticationService', $partToRun);
    $inMemoryUserRepository = sprintf('\App\ReactiveSystem\%s\Infrastructure\InMemory\InMemoryUserRepository', $partToRun);
    $inMemoryProductRepository = sprintf('\App\ReactiveSystem\%s\Infrastructure\InMemory\InMemoryProductRepository', $partToRun);
    $user = sprintf('\App\ReactiveSystem\%s\Domain\User\User', $partToRun);
    $product = sprintf('\App\ReactiveSystem\%s\Domain\Product\Product', $partToRun);
    $productDetails = sprintf('\App\ReactiveSystem\%s\Domain\Product\ProductDetails', $partToRun);
    $classesToRegister = [
        sprintf("App\ReactiveSystem\%s\Domain\Notification\NotificationSender", $partToRun) => new $stubNotificationSender(),
        sprintf("App\ReactiveSystem\%s\Domain\Shipping\ShippingService", $partToRun) => new $stubShippingService(),
        sprintf("App\ReactiveSystem\%s\Domain\Clock", $partToRun) => new $systemClock(),
        sprintf("App\ReactiveSystem\%s\Domain\Order\OrderRepository", $partToRun) => new $inMemoryOrderRepository(),
        sprintf("App\ReactiveSystem\%s\Infrastructure\Authentication\AuthenticationService", $partToRun) => new $authenticationService($userId),
        sprintf("App\ReactiveSystem\%s\Domain\User\UserRepository", $partToRun) => new $inMemoryUserRepository([new $user($userId, "John Travolta")]),
        sprintf("App\ReactiveSystem\%s\Domain\Product\ProductRepository", $partToRun) => new $inMemoryProductRepository([new $product($chairProductId, new $productDetails("Chair", Money::EUR('50.00'))), new $product($tableProductId, new $productDetails("Table", Money::EUR('100.00')))])
    ];

    return EcotoneLiteApplication::bootstrap(
        serviceConfiguration: ServiceConfiguration::createWithDefaults()
            ->doNotLoadCatalog()
            ->withNamespaces(["App\ReactiveSystem\\" . $partToRun]),
        pathToRootCatalog: __DIR__,
        classesToRegister: $classesToRegister
    );
}