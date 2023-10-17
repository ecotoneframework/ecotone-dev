<?php

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

$app->make(\Illuminate\Foundation\Http\Kernel::class)->bootstrap();

$messagingSystem =$app->get(ConfiguredMessagingSystem::class);
$orderController =$app->get(OrderController::class);
$configuration = $app->get(Configuration::class);

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

$messagingSystem->run("asynchronous", ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(2));
