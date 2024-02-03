<?php

use App\MultiTenant\Application\Command\RegisterCustomer;
use PHPUnit\Framework\Assert;

require __DIR__ . "/../boostrap.php";

$messagingSystem = bootstrapEcotone(__DIR__);
$commandBus = $messagingSystem->getCommandBus();
$queryBus = $messagingSystem->getQueryBus();

echo "Running demo:\n";

$commandBus->send(new RegisterCustomer(1, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterCustomer(2, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterCustomer(2, "John Doe"), metadata: ['tenant' => 'tenant_b']);

Assert::assertSame(
    [1,2],
    $queryBus->sendWithRouting('person.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
);

Assert::assertSame(
    [2],
    $queryBus->sendWithRouting('person.getAllRegistered', metadata: ['tenant' => 'tenant_b'])
);