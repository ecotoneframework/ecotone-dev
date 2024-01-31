<?php

use App\MultiTenant\Application\Command\RegisterCustomer;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Illuminate\Foundation\Http\Kernel;
use PHPUnit\Framework\Assert;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../../boostrap.php";
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
runMigrationForTenants();

//Config::set('database.default', 'tenant_a');
$commandBus = $app->get(CommandBus::class);
$queryBus = $app->get(QueryBus::class);

echo "Running demo:\n";

$commandBus->send(new RegisterCustomer(1, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterCustomer(2, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterCustomer(2, "John Doe"), metadata: ['tenant' => 'tenant_b']);

Assert::assertSame(
    [1,2],
    $queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
);

Assert::assertSame(
    [2],
    $queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_b'])
);