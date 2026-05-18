<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

use App\Configuration\Kernel;
use App\Domain\Command\ChangeUserName;
use App\Domain\Command\DeactivateUser;
use App\Domain\Command\RegisterUser;
use App\Domain\User;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

require __DIR__ . '/vendor/autoload.php';

$kernel = new Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();

/** @var ConfiguredMessagingSystem $messagingSystem */
$messagingSystem = $container->get(ConfiguredMessagingSystem::class);
/** @var CommandBus $commandBus */
$commandBus = $container->get(CommandBus::class);
/** @var QueryBus $queryBus */
$queryBus = $container->get(QueryBus::class);
/** @var EventStore $eventStore */
$eventStore = $container->get(EventStore::class);

echo "== Symfony Projection Quickstart - Database Read Model ==\n\n";

if ($eventStore->hasStream(User::class)) {
    $eventStore->delete(User::class);
}

echo "1) Delete projection (clean slate)\n";
$messagingSystem->runConsoleCommand('ecotone:projection:delete', ['name' => 'user_list_database']);
echo "   Projection deleted\n\n";

echo "2) Initialise projection (create read model storage)\n";
$messagingSystem->runConsoleCommand('ecotone:projection:init', ['name' => 'user_list_database']);
echo "   Projection initialised\n\n";

echo "3) Emit events via commands\n";
$aliceId = Uuid::uuid4()->toString();
$bobId   = Uuid::uuid4()->toString();
$commandBus->send(new RegisterUser($aliceId, 'Alice', 'alice@example.com'));
$commandBus->send(new RegisterUser($bobId, 'Bob', 'bob@example.com'));
$commandBus->send(new ChangeUserName($aliceId, 'Alice Cooper'));
$commandBus->send(new DeactivateUser($bobId));
echo "   Registered Alice and Bob, renamed Alice to Alice Cooper, deactivated Bob\n\n";

echo "4) Query and assert active users\n";
$rows = $queryBus->sendWithRouting('user.listActive');
Assert::assertCount(1, $rows);
Assert::assertSame('Alice Cooper', $rows[0]['name']);
echo "   Active users: " . count($rows) . " (Alice Cooper only - Bob is deactivated)\n\n";

echo "5) Reset projection (delete + re-initialise = wipe read model + clear position)\n";
$messagingSystem->runConsoleCommand('ecotone:projection:delete', ['name' => 'user_list_database']);
$messagingSystem->runConsoleCommand('ecotone:projection:init', ['name' => 'user_list_database']);
$rows = $queryBus->sendWithRouting('user.listActive');
Assert::assertSame([], $rows);
echo "   Read model is empty after reset\n\n";

echo "6) Delete projection (drop storage)\n";
$messagingSystem->runConsoleCommand('ecotone:projection:delete', ['name' => 'user_list_database']);
echo "   Projection deleted\n\n";

echo "== Example completed successfully ==\n";
