<?php

use App\MultiTenant\Application\Command\RegisterCustomer;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../boostrap.php";
$app = require __DIR__.'/bootstrap/app.php';
try {
$app->make(Kernel::class)->bootstrap();
}catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
    dump($e->getTraceAsString());
    exit(1);
}
runMigrationForTenants(DB::connection('tenant_a_connection'), DB::connection('tenant_b_connection'));

/** @var CommandBus $commandBus */
$commandBus = $app->get(CommandBus::class);
/** @var QueryBus $queryBus */
$queryBus = $app->get(QueryBus::class);

echo "Running demo:\n";

$commandBus->send(new RegisterCustomer(1, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterCustomer(2, "John Doe"), metadata: ['tenant' => 'tenant_a']);

/** Consume Messages for Tenant A */
Artisan::call('ecotone:run', ['consumerName' => 'notifications', '--stopOnFailure' => true, '--executionTimeLimit' => 1000]);

/** This is not yet consumed */
$commandBus->send(new RegisterCustomer(2, "John Doe"), metadata: ['tenant' => 'tenant_b']);

Assert::assertSame(
    2,
    $queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_a'])
);

Assert::assertSame(
    0,
    $queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_b'])
);

/** Consume Messages for Tenant B */
Artisan::call('ecotone:run', ['consumerName' => 'notifications', '--stopOnFailure' => true, '--executionTimeLimit' => 1000]);

Assert::assertSame(
    2,
    $queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_a'])
);

Assert::assertSame(
    1,
    $queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_b'])
);