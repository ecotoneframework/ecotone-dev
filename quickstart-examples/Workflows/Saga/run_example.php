<?php

use App\Workflow\Saga\Application\Order\Command\PlaceOrder;
use App\Workflow\Saga\Application\Order\Item;
use App\Workflow\Saga\Application\OrderProcess\OrderProcessStatus;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../boostrap.php";

$messagingSystem = Workflows\bootstrapEcotone(__DIR__);
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

echo "Running demo:\n";

$orderId = Uuid::uuid4()->toString();
$commandBus->send(new PlaceOrder(
    $orderId,
    '123',
    [
        new Item('milk', \Money\Money::EUR(100)),
        new Item('snickers', \Money\Money::EUR(300)),
    ]
));

$messagingSystem->run('async_saga', ExecutionPollingMetadata::createWithTestingSetup());

Assert::assertEquals(
    OrderProcessStatus::READY_TO_BE_SHIPPED,
    $queryBus->sendWithRouting(
        'orderProcess.getStatus',
        metadata: [
            'aggregate.id' => $orderId,
        ]
    )
);

echo "Demo finished.\n";