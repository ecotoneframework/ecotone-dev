<?php

use App\MultiTenant\Application\Command\RegisterCustomer;
use App\MultiTenant\Configuration\Kernel;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../boostrap.php";
$kernel = new Kernel('dev', true);
$kernel->boot();
$app = $kernel->getContainer();
runMigrationForTenants($kernel);

/** @var CommandBus $commandBus */
$commandBus = $app->get(CommandBus::class);
$output = new ConsoleOutput();

/** @var QueryBus $queryBus */
$queryBus = $app->get(QueryBus::class);
$application = new Application($kernel);
$application->setAutoExit(false);
$input = new ArrayInput(['command' => 'ecotone:run', 'consumerName' => 'notifications', '--stopOnFailure' => true, '--executionTimeLimit' => 1000]);

echo "Running demo:\n";

$commandBus->send(new RegisterCustomer(1, "John Doe"), metadata: ['tenant' => 'tenant_a']);
$commandBus->send(new RegisterCustomer(2, "John Doe"), metadata: ['tenant' => 'tenant_a']);
// tenant a
$application->run($input, $output);
// tenant b
$application->run($input, $output);
// tenant a
$application->run($input, $output);

Assert::assertSame(
    2,
    $queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_a'])
);

Assert::assertSame(
    0,
    $queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_b'])
);

$commandBus->send(new RegisterCustomer(2, "John Doe"), metadata: ['tenant' => 'tenant_b']);
// tenant b
$application->run($input, $output);

Assert::assertSame(
    2,
    $queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_a'])
);

Assert::assertSame(
    1,
    $queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_b'])
);