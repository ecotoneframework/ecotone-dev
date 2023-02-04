<?php

use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Money\Money;
use Ramsey\Uuid\UuidInterface;

function getConfiguredMessagingSystem(mixed $stageToRun, UuidInterface $userId, UuidInterface $chairProductId, UuidInterface $tableProductId): ConfiguredMessagingSystem
{
    $stubNotificationSender = sprintf('\App\ReactiveSystem\%s\Infrastructure\StubNotificationSender', $stageToRun);
    $stubShippingService = sprintf('\App\ReactiveSystem\%s\Infrastructure\StubShippingService', $stageToRun);
    $systemClock = sprintf('\App\ReactiveSystem\%s\Infrastructure\SystemClock', $stageToRun);
    $inMemoryOrderRepository = sprintf('\App\ReactiveSystem\%s\Infrastructure\InMemory\InMemoryOrderRepository', $stageToRun);
    $authenticationService = sprintf('\App\ReactiveSystem\%s\Infrastructure\Authentication\AuthenticationService', $stageToRun);
    $inMemoryUserRepository = sprintf('\App\ReactiveSystem\%s\Infrastructure\InMemory\InMemoryUserRepository', $stageToRun);
    $inMemoryProductRepository = sprintf('\App\ReactiveSystem\%s\Infrastructure\InMemory\InMemoryProductRepository', $stageToRun);
    $user = sprintf('\App\ReactiveSystem\%s\Domain\User\User', $stageToRun);
    $product = sprintf('\App\ReactiveSystem\%s\Domain\Product\Product', $stageToRun);
    $productDetails = sprintf('\App\ReactiveSystem\%s\Domain\Product\ProductDetails', $stageToRun);
    $classesToRegister = [
        sprintf("App\ReactiveSystem\%s\Domain\Notification\NotificationSender", $stageToRun) => new $stubNotificationSender(),
        sprintf("App\ReactiveSystem\%s\Domain\Shipping\ShippingService", $stageToRun) => new $stubShippingService(),
        sprintf("App\ReactiveSystem\%s\Domain\Clock", $stageToRun) => new $systemClock(),
        sprintf("App\ReactiveSystem\%s\Domain\Order\OrderRepository", $stageToRun) => new $inMemoryOrderRepository(),
        sprintf("App\ReactiveSystem\%s\Infrastructure\Authentication\AuthenticationService", $stageToRun) => new $authenticationService($userId),
        sprintf("App\ReactiveSystem\%s\Domain\User\UserRepository", $stageToRun) => new $inMemoryUserRepository([new $user($userId, "John Travolta")]),
        sprintf("App\ReactiveSystem\%s\Domain\Product\ProductRepository", $stageToRun) => new $inMemoryProductRepository([new $product($chairProductId, new $productDetails("Chair", Money::EUR('50.00'))), new $product($tableProductId, new $productDetails("Table", Money::EUR('100.00')))])
    ];

    return EcotoneLiteApplication::bootstrap(
        serviceConfiguration: ServiceConfiguration::createWithDefaults()
            ->doNotLoadCatalog()
            ->withNamespaces(["App\ReactiveSystem\\" . $stageToRun]),
        pathToRootCatalog: __DIR__,
        classesToRegister: $classesToRegister
    );
}