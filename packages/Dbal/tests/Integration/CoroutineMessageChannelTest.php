<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\Attribute\Header;
use RuntimeException;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Test\CoroutineTesting;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineMessageChannelTest extends TestCase
{
    public function test_handler_starts_after_the_sender_commits_and_sees_its_data(): void
    {
        $factory = new DbalConnectionFactory(getenv('DATABASE_MYSQL'));
        $connection = $factory->establishConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS coroutine_channel_orders');
        $connection->executeStatement('CREATE TABLE coroutine_channel_orders (id INTEGER PRIMARY KEY)');
        $handler = new class {
            public Connection $senderConnection;
            public array $observed = [];

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 1, pointcut: CommandBus::class)]
            public function pause_before_commit(MethodInvocation $invocation): mixed
            {
                $result = $invocation->proceed();
                usleep(20_000);
                return $result;
            }


            #[CommandHandler('order.place')]
            public function place(int $id, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
            {
                $this->senderConnection = $connection;
                $connection->insert('coroutine_channel_orders', ['id' => $id]);
                $bus->sendWithRouting('order.notify', $id);
            }

            #[Asynchronous('async')]
            #[CommandHandler('order.notify', endpointId: 'order.notify.endpoint')]
            public function notify(int $id, #[CurrentConnection] Connection $connection): void
            {
                $senderIsInTransaction = $this->senderConnection->isTransactionActive();
                $this->observed[] = [$senderIsInTransaction, (int) $connection->fetchOne('SELECT COUNT(*) FROM coroutine_channel_orders WHERE id = ?', [$id])];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => $factory],
            extensionObjects: [CoroutineMessageChannelBuilder::create('async'), DbalConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('order.place', 1));

        $this->assertSame([[false, 1]], $handler->observed);
    }
    public function test_failed_message_goes_to_dbal_dead_letter_and_replays_outside_a_coroutine(): void
    {
        $handler = new class {
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

}
