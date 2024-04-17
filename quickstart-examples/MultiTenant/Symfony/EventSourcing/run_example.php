<?php

use App\MultiTenant\Application\Command\RegisterCustomer;
use App\MultiTenant\Configuration\Kernel;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use PHPUnit\Framework\Assert;
use App\MultiTenant\Application\Command\RegisterProduct;
use App\MultiTenant\Application\Command\UnregisterProduct;
use Ramsey\Uuid\Uuid;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../boostrap.php";
$kernel = new Kernel('dev', true);
$kernel->boot();
$app = $kernel->getContainer();
runMigrationForTenants($kernel);

/** @var CommandBus $commandBus */
$commandBus = $app->get(CommandBus::class);
/** @var QueryBus $queryBus */
$queryBus = $app->get(QueryBus::class);

echo "Running demo:\n";

$laptopId = Uuid::uuid4();
$commandBus->send(
    new RegisterProduct($laptopId, 'Laptop'),
    metadata: [
        'tenant' => 'tenant_a'
    ]
);
$commandBus->send(
    new RegisterProduct(Uuid::uuid4(), 'Tablet'),
    metadata: [
        'tenant' => 'tenant_a'
    ]
);
$commandBus->send(
    new RegisterProduct(Uuid::uuid4(), 'Phone'),
    metadata: [
        'tenant' => 'tenant_b'
    ]
);

Assert::assertEquals(
    ['Laptop', 'Tablet'],
    $queryBus->sendWithRouting('product.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
);
Assert::assertEquals(
    ['Phone'],
    $queryBus->sendWithRouting('product.getAllRegistered', metadata: ['tenant' => 'tenant_b'])
);

$commandBus->send(
    new UnregisterProduct($laptopId),
    metadata: [
        'tenant' => 'tenant_a'
    ]
);

Assert::assertEquals(
    ['Tablet'],
    $queryBus->sendWithRouting('product.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
);
Assert::assertEquals(
    ['Phone'],
    $queryBus->sendWithRouting('product.getAllRegistered', metadata: ['tenant' => 'tenant_b'])
);