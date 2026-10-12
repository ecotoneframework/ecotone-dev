<?php

use App\Workflow\Saga\Application\Order\Command\PlaceOrder;
use App\Workflow\Saga\Application\Order\Item;
use App\Workflow\Saga\Application\OrderProcess\OrderProcessStatus;
use App\Workflow\Saga\Application\Payment\PaymentProcessor;
use App\Workflow\Saga\Application\Payment\PaymentService;
use App\Workflow\Saga\Infrastructure\Converter;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../boostrap.php";

$messagingSystem = Workflows\bootstrapEcotone(__DIR__, [
    new PaymentService(new PaymentProcessor()),
    new Converter(),
]);
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