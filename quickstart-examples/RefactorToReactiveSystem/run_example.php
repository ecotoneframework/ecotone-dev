<?php

use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . "/vendor/autoload.php";

Assert::assertTrue(key_exists(1, $argv) && in_array($argv[1], ["Part_1"]), sprintf('Pass correct part which you want to run for example: "php run_example Part_1"'));
$partToRun = $argv[1];
$userId = Uuid::uuid4();
$tableProductId = Uuid::uuid4();
$chairProductId = Uuid::uuid4();

$classesToRegister = match ($partToRun) {
    "Part_1" => [
        \App\ReactiveSystem\Part_1\Domain\Notification\NotificationSender::class => new \App\ReactiveSystem\Part_1\Infrastructure\StubNotificationSender(),
        \App\ReactiveSystem\Part_1\Domain\Shipping\ShippingService::class => new \App\ReactiveSystem\Part_1\Infrastructure\StubShippingService(),
        \App\ReactiveSystem\Part_1\Domain\Clock::class => new \App\ReactiveSystem\Part_1\Infrastructure\SystemClock(),
        \App\ReactiveSystem\Part_1\Domain\Order\OrderRepository::class => \App\ReactiveSystem\Part_1\Infrastructure\InMemory\InMemoryOrderRepository::createEmpty(),
        \App\ReactiveSystem\Part_1\Infrastructure\Authentication\AuthenticationService::class => new \App\ReactiveSystem\Part_1\Infrastructure\Authentication\StubAuthenticationService($userId),
        \App\ReactiveSystem\Part_1\Domain\User\UserRepository::class => new \App\ReactiveSystem\Part_1\Infrastructure\InMemory\InMemoryUserRepository([new App\ReactiveSystem\Part_1\Domain\User\User($userId, "John Travolta")]),
        \App\ReactiveSystem\Part_1\Domain\Product\ProductRepository::class => new \App\ReactiveSystem\Part_1\Infrastructure\InMemory\InMemoryProductRepository([new \App\ReactiveSystem\Part_1\Domain\Product\Product($chairProductId, new \App\ReactiveSystem\Part_1\Domain\Product\ProductDetails("Chair", \Money\Money::EUR('50.00'))), new \App\ReactiveSystem\Part_1\Domain\Product\Product($tableProductId, new \App\ReactiveSystem\Part_1\Domain\Product\ProductDetails("Table", \Money\Money::EUR('100.00')))])
    ]
};

$messagingSystem = EcotoneLiteApplication::bootstrap(
    serviceConfiguration: ServiceConfiguration::createWithDefaults()
        ->doNotLoadCatalog()
        ->withNamespaces([$partToRun]),
    pathToRootCatalog: __DIR__,
    classesToRegister: $classesToRegister
);

/** Run Controller - Actions starts */

/** @var \App\ReactiveSystem\OrderController $controller */
$orderController = $messagingSystem->getServiceFromContainer(sprintf("App\ReactiveSystem\%s\UI\OrderController", $partToRun));

$orderController->placeOrder(new Request(content: json_encode([
    'address' => [
        'street' => 'Washington',
        'houseNumber' => '15',
        'postCode' => '81-221',
        'country' => 'Netherlands'
    ],
    'productIds' => [$tableProductId->toString(), $chairProductId->toString()]
])));