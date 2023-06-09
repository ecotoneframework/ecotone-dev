<?php

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use Monorepo\ExampleApp\Symfony\Kernel;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$kernel = new Kernel('prod', false);
$kernel->boot();
$container = $kernel->getContainer();

$messagingSystem = $container->get(ConfiguredMessagingSystem::class);
$orderController = $container->get(OrderController::class);
$configuration = $container->get(Configuration::class);

$orderController->placeOrder(new Request(content: json_encode([
    'orderId' => Uuid::uuid4()->toString(),
    'address' => [
        'street' => 'Washington',
        'houseNumber' => '15',
        'postCode' => '81-221',
        'country' => 'Netherlands'
    ],
    'productId' => $configuration->productId(),
])));

$kernel->shutdown();