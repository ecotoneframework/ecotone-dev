<?php

use App\MultiTenant\Application\Command\RegisterCustomer;
use App\MultiTenant\Configuration\Kernel;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use PHPUnit\Framework\Assert;

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