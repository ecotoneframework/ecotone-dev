<?php

use App\Domain\Customer\Command\ChangeEmail;
use App\Domain\Customer\Command\RegisterCustomer;
use App\Domain\Customer\Email;
use App\Domain\Customer\FullName;
use App\Domain\Order\Command\PlaceOrder;
use App\Domain\Order\OrderStatus;
use App\Domain\OrderSaga\ProductReservationService;
use App\Domain\Product\Command\CreateProduct;
use Assert\Assert;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Enqueue\Dbal\DbalConnectionFactory;
use Money\Money;
use Ramsey\Uuid\Uuid;

/** This is production usage, which stores everything in the database */

require __DIR__ . "/vendor/autoload.php";
$ecotoneLite = EcotoneLiteApplication::bootstrap([
    DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
    ProductReservationService::class => new ProductReservationService(true)
], pathToRootCatalog: __DIR__);
$commandBus = $ecotoneLite->getCommandBus();
$queryBus = $ecotoneLite->getQueryBus();

echo "Registering customer\n";
$customerId = Uuid::uuid4();
$commandBus->send(new RegisterCustomer(
    $customerId,
    new FullName('John Doe'),
    new Email('some@wp.pl')
));

echo "Creating product\n";
$productId = Uuid::uuid4();
$commandBus->send(new CreateProduct(
    $productId,
    'Wooden Table',
    Money::EUR(100)
));

echo "Placing an order\n";
$orderId = Uuid::uuid4();
$commandBus->send(new PlaceOrder(
    $orderId,
    [$productId]
), metadata: ['executorId' => $customerId->toString()]);

$ecotoneLite->run('orders', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 100));

/** @var OrderStatus $orderStatus */
$orderStatus = $queryBus->sendWithRouting('order.get_status', metadata: ['aggregate.id' => $orderId]);

Assert::that($orderStatus->value)->eq(OrderStatus::COMPLETED->value);
echo "Order was placed successfully\n";

try {
    $commandBus->send(new ChangeEmail(
        $customerId,
        new Email('some@wp.fr'),
    ));
    Assert::that(false)->true("Exception should be thrown");
} catch (InvalidArgumentException) {
    echo "Refused access to ChangeEmail action\n";
}

$commandBus->send(new ChangeEmail(
    $customerId,
    new Email('some@email.fr'),
), [
    'executorEmail' => 'some@wp.pl'
]);

echo "Allowed access to ChangeEmail action\n";
