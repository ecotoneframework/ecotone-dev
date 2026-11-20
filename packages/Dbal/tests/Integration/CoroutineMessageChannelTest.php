<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Dbal\Attribute\DbalTransaction;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Test\CoroutineTesting;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Event;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineMessageChannelTest extends TestCase
{
    #[DataProvider('sending_boundaries')]
    public function test_handler_starts_after_the_sender_commits_and_sees_its_data(string $boundary): void
    {
        $factory = new DbalConnectionFactory(getenv('DATABASE_MYSQL'));
        $connection = $factory->establishConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS coroutine_channel_orders');
        $connection->executeStatement('CREATE TABLE coroutine_channel_orders (id INTEGER PRIMARY KEY)');
        $handler = new class () {
            public Connection $senderConnection;
            public array $observed = [];
            public Coroutine\Channel $notified;

            public function __construct()
            {
                $this->notified = new Coroutine\Channel();
            }

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 1, pointcut: CommandBus::class . '||' . AsynchronousRunningEndpoint::class . '||' . MessageGateway::class)]
            public function pause_before_commit(MethodInvocation $invocation): mixed
            {
                $result = $invocation->proceed();
                $nextTick = new Coroutine\Channel();
                Event::defer(static fn () => $nextTick->push(true));
                $nextTick->pop();
                return $result;
            }


            #[CommandHandler('order.place')]
            public function place(int $id, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
            {
                $this->senderConnection = $connection;
                $connection->insert('coroutine_channel_orders', ['id' => $id]);
                $bus->sendWithRouting('order.notify', $id);
            }

            #[InternalHandler('order.gateway')]
            public function place_through_gateway(int $id, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
            {
                $this->place($id, $connection, $bus);
            }

            #[Asynchronous('senders')]
            #[CommandHandler('order.place.async', endpointId: 'order.place.async.endpoint')]
            public function place_asynchronously(int $id, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
            {
                $this->place($id, $connection, $bus);
            }

            #[Asynchronous('async')]
            #[CommandHandler('order.notify', endpointId: 'order.notify.endpoint')]
            public function notify(int $id, #[CurrentConnection] Connection $connection): void
            {
                $senderIsInTransaction = $this->senderConnection->isTransactionActive();
                $this->observed[] = [$senderIsInTransaction, (int) $connection->fetchOne('SELECT COUNT(*) FROM coroutine_channel_orders WHERE id = ?', [$id])];
                $this->notified->push(true);
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handler::class, CoroutineOrderGateway::class],
            [$handler, DbalConnectionFactory::class => $factory],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), SimpleMessageChannelBuilder::createQueueChannel('senders'), DbalConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        CoroutineTesting::runAtOnce(static function () use ($ecotone, $boundary, $handler): void {
            if ($boundary === 'gateway') {
                $ecotone->getGateway(CoroutineOrderGateway::class)->place(1);
            } elseif ($boundary === 'asynchronous') {
                $ecotone->sendCommandWithRouting('order.place.async', 1);
                $ecotone->run('senders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
            } else {
                $ecotone->sendCommandWithRouting('order.place', 1);
            }
            $handler->notified->pop();
        });

        $this->assertSame([[false, 1]], $handler->observed);
    }
    public function test_failed_message_goes_to_dbal_dead_letter_and_replays_outside_a_coroutine(): void
    {
        $handler = new class () {
            public bool $fail = true;
            public array $handled = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload, #[Header('tenant')] string $tenant): void
            {
                if ($this->fail) {
                    throw new RuntimeException('handling failed');
                }
                $this->handled[] = [$payload, $tenant, Coroutine::getCid()];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_MYSQL'))],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults(), PollingMetadata::create('async')->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $deadLetter = $ecotone->getGateway(DeadLetterGateway::class);
        $deadLetter->deleteAll();

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload', metadata: ['tenant' => 'replayed-tenant']));

        $this->assertSame(1, $deadLetter->count());
        $handler->fail = false;
        $deadLetter->replayAll();
        $this->assertCount(1, $handler->handled);
        $this->assertSame(['payload', 'replayed-tenant'], array_slice($handler->handled[0], 0, 2));
        $this->assertLessThanOrEqual(0, $handler->handled[0][2]);
        $this->assertSame(0, $deadLetter->count());
    }


    public function test_concurrent_messages_keep_their_own_headers_and_transaction(): void
    {
        $factory = new DbalConnectionFactory(getenv('DATABASE_MYSQL'));
        $connection = $factory->establishConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS coroutine_channel_deliveries');
        $connection->executeStatement('CREATE TABLE coroutine_channel_deliveries (id INTEGER PRIMARY KEY, tenant VARCHAR(255))');
        $handler = new class () {
            public array $before = [];
            public array $after = [];
            private Coroutine\Channel $release;
            private int $started = 0;

            public function __construct()
            {
                $this->release = new Coroutine\Channel();
            }

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(array $payload, #[CurrentConnection] Connection $connection, #[Header('tenant')] string $tenant): void
            {
                $this->before[$payload['id']] = [$tenant, $connection->isTransactionActive(), spl_object_id($connection)];
                $connection->insert('coroutine_channel_deliveries', ['id' => $payload['id'], 'tenant' => $tenant]);
                $this->started++;
                if ($this->started === 2) {
                    $this->release->close();
                } else {
                    $this->release->pop();
                }
                $this->after[$payload['id']] = [$tenant, $connection->isTransactionActive(), spl_object_id($connection)];
                if ($payload['fail']) {
                    throw new RuntimeException('delivery failed');
                }
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => $factory],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async')->withCoroutines(2), DbalConfiguration::createWithDefaults(), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        CoroutineTesting::runAtOnce(static function () use ($ecotone): void {
            $ecotone->sendCommandWithRouting('handle', ['id' => 1, 'fail' => true], metadata: ['tenant' => 'refused']);
            $ecotone->sendCommandWithRouting('handle', ['id' => 2, 'fail' => false], metadata: ['tenant' => 'accepted']);
        });

        $this->assertSame($handler->before[1], $handler->after[1]);
        $this->assertSame($handler->before[2], $handler->after[2]);
        $this->assertSame(['refused', true], array_slice($handler->before[1], 0, 2));
        $this->assertSame(['accepted', true], array_slice($handler->before[2], 0, 2));
        $this->assertNotSame($handler->before[1][2], $handler->before[2][2]);
        $this->assertSame(['accepted'], $factory->establishConnection()->fetchFirstColumn('SELECT tenant FROM coroutine_channel_deliveries ORDER BY id'));
    }


    public function test_caught_inner_failure_commits_its_rows_and_releases_both_commands_messages_with_the_outer_command(): void
    {
        $factory = new DbalConnectionFactory(getenv('DATABASE_MYSQL'));
        $connection = $factory->establishConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS coroutine_channel_nested_orders');
        $connection->executeStatement('CREATE TABLE coroutine_channel_nested_orders (id INTEGER PRIMARY KEY)');
        $handler = new class () {
            public array $handled = [];
            public string $caught = '';

            #[CommandHandler('outer')]
            public function outer(int $id, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
            {
                $connection->insert('coroutine_channel_nested_orders', ['id' => $id]);
                $bus->sendWithRouting('notify', $id);
                try {
                    $bus->sendWithRouting('inner', $id + 1);
                } catch (RuntimeException $exception) {
                    $this->caught = $exception->getMessage();
                }
            }

            #[CommandHandler('inner')]
            public function inner(int $id, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
            {
                $connection->insert('coroutine_channel_nested_orders', ['id' => $id]);
                $bus->sendWithRouting('notify', $id);
                throw new RuntimeException('inner failed');
            }

            #[Asynchronous('async')]
            #[CommandHandler('notify', endpointId: 'notify.endpoint')]
            public function notify(int $id, #[CurrentConnection] Connection $connection): void
            {
                $this->handled[$id] = (int) $connection->fetchOne('SELECT COUNT(*) FROM coroutine_channel_nested_orders WHERE id = ?', [$id]);
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => $factory],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults(), InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('outer', 0));

        ksort($handler->handled);
        $this->assertSame('inner failed', $handler->caught);
        $this->assertSame([1, 1], $handler->handled);
        $this->assertSame([0, 1], array_map('intval', $factory->establishConnection()->fetchFirstColumn('SELECT id FROM coroutine_channel_nested_orders ORDER BY id')));
    }

    public static function sending_boundaries(): array
    {
        return [['command_bus'], ['gateway'], ['asynchronous']];
    }

}

interface CoroutineOrderGateway
{
    #[DbalTransaction]
    #[MessageGateway('order.gateway')]
    public function place(int $id): void;
}
