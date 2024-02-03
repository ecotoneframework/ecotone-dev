<?php

use App\MultiTenant\Application\Command\RegisterCustomer;
use App\MultiTenant\Application\RegisterPerson;
use PHPUnit\Framework\Assert;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../boostrap.php";

$messagingSystem = bootstrapEcotone(__DIR__);
runMigrationForTenants();
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

echo "Running demo:\n";

$commandBus->send(new RegisterPerson(1, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterPerson(2, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterPerson(2, "John Doe"), metadata: ['tenant' => 'tenant_b']);

Assert::assertSame(
    [1,2],
    $queryBus->sendWithRouting('person.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
);

Assert::assertSame(
    [2],
    $queryBus->sendWithRouting('person.getAllRegistered', metadata: ['tenant' => 'tenant_b'])
);