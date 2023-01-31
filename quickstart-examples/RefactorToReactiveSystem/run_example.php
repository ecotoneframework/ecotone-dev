<?php

use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . "/vendor/autoload.php";
require __DIR__ . '/configuration.php';

Assert::assertTrue(key_exists(1, $argv) && in_array($argv[1], ["Part_1", "Part_2"]), sprintf('Pass correct part which you want to run for example: "php run_example Part_1"'));
$partToRun = $argv[1];
$userId = Uuid::uuid4();
$tableProductId = Uuid::uuid4();
$chairProductId = Uuid::uuid4();

$messagingSystem = getConfiguredMessagingSystem($partToRun, $userId, $chairProductId, $tableProductId);

/** Run Controller  */

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

if ($partToRun !== 'Part_1') {
    $messagingSystem->run("asynchronous", ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(2));
}