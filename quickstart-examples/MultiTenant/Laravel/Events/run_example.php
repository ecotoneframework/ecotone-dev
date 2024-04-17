<?php

use App\MultiTenant\Application\Command\RegisterCustomer;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../boostrap.php";
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
runMigrationForTenants(DB::connection('tenant_a_connection'), DB::connection('tenant_b_connection'));

/** @var CommandBus $commandBus */
$commandBus = $app->get(CommandBus::class);
/** @var QueryBus $queryBus */
$queryBus = $app->get(QueryBus::class);

echo "Running demo:\n";

$commandBus->send(new RegisterCustomer(1, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterCustomer(2, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterCustomer(2, "John Doe"), metadata: ['tenant' => 'tenant_b']);

Assert::assertSame(
    2,
    $queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_a'])
);

Assert::assertSame(
    1,
    $queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_b'])
);