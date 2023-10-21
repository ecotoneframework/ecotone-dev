<?php

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$bootstrap = require __DIR__.'/app.php';

$messagingSystem =  $bootstrap();
$orderController = $messagingSystem->getServiceFromContainer(OrderController::class);
$configuration = $messagingSystem->getServiceFromContainer(Configuration::class);

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
