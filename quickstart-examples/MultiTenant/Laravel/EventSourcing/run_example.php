<?php

use App\MultiTenant\Application\Command\RegisterCustomer;
use App\MultiTenant\Application\Command\RegisterProduct;
use App\MultiTenant\Application\Command\UnregisterProduct;
use Ecotone\EventSourcing\ProjectionManager;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../boostrap.php";
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
runMigrationForTenants(DB::connection('tenant_a_connection'), DB::connection('tenant_b_connection'));

/** @var CommandBus $commandBus */
$commandBus = $app->get(CommandBus::class);
/** @var QueryBus $queryBus */
$queryBus = $app->get(QueryBus::class);
/** @var ProjectionManager $projectionManager */
$projectionManager = $app->get(ProjectionManager::class);
$projectionManager->deleteProjection('registered_products', metadata: ['tenant' => 'tenant_a']);
$projectionManager->deleteProjection('registered_products', metadata: ['tenant' => 'tenant_b']);
echo "Running demo:\n";

$laptopId = Uuid::uuid4();
$commandBus->send(
    new RegisterProduct($laptopId, "Laptop"),
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