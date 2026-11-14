<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\TransactionIsolationLevel;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Messaging\CollectedReleaseReason;
use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class CollectingConcurrencyTest extends TestCase
{
    use ConcurrentConsumers;

    public function test_batch_is_released_once_when_last_messages_are_recorded_in_a_real_database_race(): void
    {
        $this->proveConcurrentBatch(true);
    }

    public function test_consumers_opening_the_same_open_slot_in_a_real_database_race_retry_and_keep_one_batch(): void
    {
        $this->proveConcurrentBatch(false);
    }

    public function test_every_original_message_redelivered_after_a_real_database_race_is_not_collected_again(): void
    {
        $this->proveConcurrentBatch(alreadyOpen: false, replayOriginals: true);
    }

    public function test_cleanup_racing_two_arriving_messages_keeps_one_open_batch_in_a_real_database_race(): void
    {
        $this->proveOpenSlotRace(cleanup: true);
    }

    public function test_discard_racing_an_arriving_message_frees_the_slot_atomically_in_a_real_database_race(): void
    {
        $this->proveOpenSlotRace(cleanup: false);
    }

    public static function exhaustedOperations(): iterable
    {
        yield 'append' => [false];
        yield 'manual_release' => [true];
    }

    public function test_same_message_delivered_twice_at_once_while_its_batch_closes_is_a_lost_write_and_collected_once_after_replay(): void
    {
        $connection = new DbalConnectionFactory(getenv('DATABASE_DSN'));
        if ($connection->establishConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The stale-snapshot race requires PostgreSQL, MySQL or MariaDB.');
        }
        $connection->establishConnection()->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 1, groupBy: 'payload["key"]')]
            #[Asynchronous('duplicate_collecting')]
            #[CommandHandler('collect.duplicate', endpointId: 'collect.duplicate.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = array_column(array_column($batch->messages, 'payload'), 'value');
            }
        };
        $interleaving = new class () {
            public ?Closure $compete = null;

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 101, pointcut: AsynchronousRunningEndpoint::class)]
            public function deliverTheSameMessageElsewhere(MethodInvocation $invocation, #[Reference] DocumentStore $documents): mixed
            {
                if ($this->compete !== null) {
                    $documents->getAllDocuments('collecting_duplicate_snapshot');
                    $compete = $this->compete;
                    $this->compete = null;
                    $compete();
                }
                return $invocation->proceed();
            }
        };
        $extensions = [SimpleMessageChannelBuilder::createQueueChannel('duplicate_collecting'), DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter'), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)];
        $first = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, $interleaving::class],
            containerOrAvailableServices: [$handler, $interleaving, DbalConnectionFactory::class => $connection],
            extensionObjects: $extensions,
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $second = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: $extensions,
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $key = bin2hex(random_bytes(16));
        $messageId = bin2hex(random_bytes(16));
        $interleaving->compete = static function () use ($second, $key, $messageId): void {
            $second->sendCommandWithRouting('collect.duplicate', ['key' => $key, 'value' => 'duplicate'], metadata: [MessageHeaders::MESSAGE_ID => $messageId]);
            $second->run('duplicate_collecting');
        };
        $first->sendCommandWithRouting('collect.duplicate', ['key' => $key, 'value' => 'duplicate'], metadata: [MessageHeaders::MESSAGE_ID => $messageId]);
        $first->run('duplicate_collecting', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false)->withExecutionAmountLimit(1));

        $deadLetters = $first->getGateway(DeadLetterGateway::class);
        $failure = array_values(array_filter($deadLetters->list(1000, 0), static fn ($failure): bool => $failure->getMessageId() === $messageId));
        self::assertCount(1, $failure);
        self::assertSame(ConcurrencyException::class, $failure[0]->getExceptionClass());
        $deadLetters->replay($messageId);
        $first->run('duplicate_collecting');

        self::assertSame([['duplicate']], $handler->batches);
        self::assertSame([], array_values(array_filter($deadLetters->list(1000, 0), static fn ($failure): bool => $failure->getMessageId() === $messageId)));
        self::assertSame([], array_values(array_filter($first->getGateway(GatheredGroups::class)->listOpenBatches('collect.duplicate.handler'), static fn (array $batch): bool => $batch['groupKey'] === $key)));
        self::assertCount(1, array_filter($first->getGateway(GatheredGroups::class)->listClosedBatches('collect.duplicate.handler'), static fn (array $batch): bool => $batch['groupKey'] === $key));
    }

    public function test_lock_wait_timeout_surfaces_as_itself_rather_than_as_a_lost_optimistic_write(): void
    {
        $connection = new DbalConnectionFactory(getenv('DATABASE_DSN'));
        $platform = $connection->establishConnection()->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers with a database-wide lock instead of row locks.');
        }
        $connection->establishConnection()->executeStatement($platform instanceof PostgreSQLPlatform ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        $handler = new class () {
            #[Collected(maxSize: 100, groupBy: 'payload["key"]')]
            #[Asynchronous('locked_collecting')]
            #[CommandHandler('collect.locked', endpointId: 'collect.locked.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $holder = new class () {
            public ?Closure $whileHoldingTheLock = null;

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 101, pointcut: AsynchronousRunningEndpoint::class)]
            public function holdTheBatchLock(MethodInvocation $invocation): mixed
            {
                $result = $invocation->proceed();
                if ($this->whileHoldingTheLock !== null) {
                    $whileHoldingTheLock = $this->whileHoldingTheLock;
                    $this->whileHoldingTheLock = null;
                    $whileHoldingTheLock();
                }
                return $result;
            }
        };
        $extensions = [SimpleMessageChannelBuilder::createQueueChannel('locked_collecting'), DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)];
        $waiting = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $connection],
            extensionObjects: $extensions,
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $locking = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, $holder::class],
            containerOrAvailableServices: [$handler, $holder, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: $extensions,
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $key = bin2hex(random_bytes(16));
        $locking->sendCommandWithRouting('collect.locked', ['key' => $key, 'value' => 'seed']);
        $locking->run('locked_collecting');
        $failure = null;
        $holder->whileHoldingTheLock = static function () use ($waiting, $key, &$failure): void {
            $waiting->sendCommandWithRouting('collect.locked', ['key' => $key, 'value' => 'waiting']);
            try {
                $waiting->run('locked_collecting', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1)->withExecutionAmountLimit(1));
            } catch (Throwable $exception) {
                $failure = $exception;
            }
        };
        $locking->sendCommandWithRouting('collect.locked', ['key' => $key, 'value' => 'holder']);
        $locking->run('locked_collecting');

        self::assertInstanceOf(Throwable::class, $failure);
        self::assertNotInstanceOf(ConcurrencyException::class, $failure);
        self::assertMatchesRegularExpression('/lock/i', $failure->getMessage());
    }

    #[DataProvider('exhaustedOperations')]
    public function test_exhausted_conflicts_reach_the_dead_letter_and_replay_completes_the_batch_in_a_real_database_race(bool $release): void
    {
        $connection = new DbalConnectionFactory(getenv('DATABASE_DSN'));
        if ($connection->establishConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The stale-snapshot race requires PostgreSQL, MySQL or MariaDB.');
        }
        $connection->establishConnection()->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 100, groupBy: 'payload["key"]')]
            #[Asynchronous('exhausted_collecting')]
            #[CommandHandler('collect.exhaustion', endpointId: 'collect.exhaustion.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $interleaving = new class () {
            public ?Closure $compete = null;
            public int $attempts = 0;
            public array $messageIds = [];

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 101, pointcut: AsynchronousRunningEndpoint::class)]
            public function conflict(MethodInvocation $invocation, #[Reference] DocumentStore $documents, Message $message): mixed
            {
                $this->messageIds[] = $message->getHeaders()->getMessageId();
                if ($this->compete !== null) {
                    $documents->getAllDocuments('collecting_exhaustion_snapshot');
                    ($this->compete)(++$this->attempts);
                }
                return $invocation->proceed();
            }
        };
        $extensions = [SimpleMessageChannelBuilder::createQueueChannel('exhausted_collecting'), DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter'), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(true, 1)];
        $first = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, $interleaving::class],
            containerOrAvailableServices: [$handler, $interleaving, DbalConnectionFactory::class => $connection],
            extensionObjects: $extensions,
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $second = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: $extensions,
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $key = bin2hex(random_bytes(16));
        $second->sendCommandWithRouting('collect.exhaustion', ['key' => $key, 'value' => 'seed']);
        $second->run('exhausted_collecting');
        $interleaving->compete = static function (int $attempt) use ($second, $key): void {
            $second->sendCommandWithRouting('collect.exhaustion', ['key' => $key, 'value' => 'competitor-' . $attempt]);
            $second->run('exhausted_collecting');
        };
        $messageId = bin2hex(random_bytes(16));
        if ($release) {
            $seed = array_values(array_filter($second->getGateway(GatheredGroups::class)->listOpenBatches('collect.exhaustion.handler'), static fn (array $batch): bool => $batch['groupKey'] === $key))[0];
            $first->getGateway(GatheredGroups::class)->requestRelease($seed['groupId']);
        } else {
            $first->sendCommandWithRouting('collect.exhaustion', ['key' => $key, 'value' => 'original'], metadata: [MessageHeaders::MESSAGE_ID => $messageId]);
        }
        $first->run('exhausted_collecting', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false)->withExecutionAmountLimit(1));
        self::assertSame(2, $interleaving->attempts);
        $messageId = $interleaving->messageIds[0];
        $deadLetters = $first->getGateway(DeadLetterGateway::class);
        $failure = array_values(array_filter($deadLetters->list(1000, 0), static fn ($failure): bool => $failure->getMessageId() === $messageId));
        self::assertCount(1, $failure);
        self::assertSame(ConcurrencyException::class, $failure[0]->getExceptionClass());
        self::assertStringContainsString('withAsynchronousEndpointsRetry(true, 5)', $failure[0]->getMessage());
        self::assertStringContainsString('run one consumer', $failure[0]->getMessage());
        $interleaving->compete = null;
        $deadLetters->replay($messageId);
        $first->run('exhausted_collecting');
        self::assertSame([], array_values(array_filter($deadLetters->list(1000, 0), static fn ($failure): bool => $failure->getMessageId() === $messageId)));
        $open = array_values(array_filter($first->getGateway(GatheredGroups::class)->listOpenBatches('collect.exhaustion.handler'), static fn (array $batch): bool => $batch['groupKey'] === $key));
        if ($release) {
            self::assertSame([], $open);
        } else {
            self::assertCount(1, $open);
            self::assertSame(4, $open[0]['recordedMessages']);
            $first->getGateway(GatheredGroups::class)->requestRelease($open[0]['groupId']);
            $first->run('exhausted_collecting');
        }
        self::assertCount(1, $handler->batches);
        self::assertSame($release ? ['seed', 'competitor-1', 'competitor-2'] : ['seed', 'competitor-1', 'competitor-2', 'original'], array_column(array_column($handler->batches[0]->messages, 'payload'), 'value'));
    }

    private function proveOpenSlotRace(bool $cleanup): void
    {
        $connection = new DbalConnectionFactory(getenv('DATABASE_DSN'));
        if ($connection->establishConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers with a database-wide lock, so this two-connection race is proved on PostgreSQL, MySQL and MariaDB only.');
        }
        $connection->establishConnection()->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 2, groupBy: 'payload["key"]')]
            #[Asynchronous('cleanup_race')]
            #[CommandHandler('collect.cleanup.race', endpointId: 'collect.cleanup.race.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $interleaving = new class () {
            public ?Closure $betweenConsumers = null;

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 101, pointcut: AsynchronousRunningEndpoint::class)]
            public function interleave(MethodInvocation $invocation, #[Reference] DocumentStore $documents): mixed
            {
                if ($this->betweenConsumers !== null) {
                    $documents->getAllDocuments('collecting_cleanup_race_snapshot');
                    $betweenConsumers = $this->betweenConsumers;
                    $this->betweenConsumers = null;
                    $betweenConsumers();
                }
                return $invocation->proceed();
            }
        };
        $first = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, $interleaving::class],
            containerOrAvailableServices: [$handler, $interleaving, DbalConnectionFactory::class => $connection],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('cleanup_race'), DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $key = bin2hex(random_bytes(16));
        $first->sendCommandWithRouting('collect.cleanup.race', ['key' => $key, 'value' => 'seed-one']);
        if ($cleanup) {
            $first->sendCommandWithRouting('collect.cleanup.race', ['key' => $key, 'value' => 'seed-two']);
        }
        $first->run('cleanup_race');
        self::assertCount($cleanup ? 1 : 0, $handler->batches);
        $discardId = $cleanup ? null : array_values(array_filter($first->getGateway(GatheredGroups::class)->listOpenBatches('collect.cleanup.race.handler'), static fn (array $batch): bool => $batch['groupKey'] === $key))[0]['groupId'];
        $first->advanceTimeBy(new TimeSpan(days: 8));
        $second = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('cleanup_race'), DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-09 12:00:00 UTC'),
        );
        $interleaving->betweenConsumers = static function () use ($second, $key, $cleanup, $discardId): void {
            if ($cleanup) {
                $second->getGateway(GatheredGroups::class)->cleanupClosedGroups();
            } else {
                $second->getGateway(GatheredGroups::class)->discard($discardId);
            }
            $second->sendCommandWithRouting('collect.cleanup.race', ['key' => $key, 'value' => 'second-connection']);
            $second->run('cleanup_race', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 5)->withExecutionAmountLimit(5));
        };
        $first->sendCommandWithRouting('collect.cleanup.race', ['key' => $key, 'value' => 'first-connection']);
        $first->run('cleanup_race', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 5)->withExecutionAmountLimit(5));
        $open = array_values(array_filter($first->getGateway(GatheredGroups::class)->listOpenBatches('collect.cleanup.race.handler'), static fn (array $batch): bool => $batch['groupKey'] === $key));
        self::assertSame([], $open);
        self::assertCount($cleanup ? 2 : 1, $handler->batches);
        self::assertSame(['second-connection', 'first-connection'], array_column(array_column($handler->batches[$cleanup ? 1 : 0]->messages, 'payload'), 'value'));
    }

    public function test_max_wait_timer_racing_an_append_releases_each_message_once_in_a_real_database_race(): void
    {
        $this->proveReleaseRace(manualRelease: false);
    }

    public function test_manual_release_racing_an_append_releases_each_message_once_in_a_real_database_race(): void
    {
        $this->proveReleaseRace(manualRelease: true);
    }

    public function test_manual_batch_release_does_not_revisit_an_internal_handlers_shared_input_in_a_real_database_race(): void
    {
        $this->proveReleaseRace(manualRelease: true, handlerKind: 'internal');
    }

    public function test_manual_batch_release_does_not_republish_an_event_in_a_real_database_race(): void
    {
        $this->proveReleaseRace(manualRelease: true, handlerKind: 'event');
    }

    private function proveReleaseRace(bool $manualRelease, string $handlerKind = 'command'): void
    {
        $connection = new DbalConnectionFactory(getenv('DATABASE_DSN'));
        if ($connection->establishConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers with a database-wide lock, so this two-connection race is proved on PostgreSQL, MySQL and MariaDB only.');
        }
        $connection->establishConnection()->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 3, maxWait: new TimeSpan(seconds: 10), groupBy: 'payload["key"]')]
            #[Asynchronous('release_race')]
            #[CommandHandler('collect.release.race', endpointId: 'collect.release.race.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        if ($handlerKind === 'internal') {
            $handler = new class () {
                public array $batches = [];
                public array $ordinary = [];

                #[CommandHandler('collect.release.race', outputChannelName: 'collect.release.race.shared')]
                public function start(array $message): array
                {
                    return $message;
                }

                #[Collected(maxSize: 3, maxWait: new TimeSpan(seconds: 10), groupBy: 'payload["key"]')]
                #[Asynchronous('release_race')]
                #[InternalHandler('collect.release.race.shared', endpointId: 'collect.release.race.handler')]
                public function collect(CollectedMessages $batch): void
                {
                    $this->batches[] = $batch;
                }

                #[InternalHandler('collect.release.race.shared', endpointId: 'ordinary.release.race.handler')]
                public function ordinary(array $message): void
                {
                    $this->ordinary[] = $message;
                }
            };
        } elseif ($handlerKind === 'event') {
            $handler = new class () {
                public array $batches = [];
                public array $ordinary = [];

                #[CommandHandler('collect.release.race')]
                public function start(array $message, #[Reference] EventBus $events): void
                {
                    $events->publishWithRouting('collect.release.race.event', $message);
                }

                #[Collected(maxSize: 3, maxWait: new TimeSpan(seconds: 10), groupBy: 'payload["key"]')]
                #[Asynchronous('release_race')]
                #[EventHandler(listenTo: 'collect.release.race.event', endpointId: 'collect.release.race.handler')]
                public function collect(CollectedMessages $batch): void
                {
                    $this->batches[] = $batch;
                }

                #[EventHandler(listenTo: 'collect.release.race.event', endpointId: 'ordinary.release.race.handler')]
                public function ordinary(array $message): void
                {
                    $this->ordinary[] = $message;
                }
            };
        }
        $interleaving = new class () {
            public ?Closure $releaseOnOtherConnection = null;
            public array $deliveryHeaders = [];

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 101, pointcut: AsynchronousRunningEndpoint::class)]
            public function interleave(MethodInvocation $invocation, #[Reference] DocumentStore $documents, Message $message): mixed
            {
                $this->deliveryHeaders[] = $message->getHeaders()->headers();
                if ($this->releaseOnOtherConnection !== null) {
                    $documents->getAllDocuments('collecting_release_race_snapshot');
                    $release = $this->releaseOnOtherConnection;
                    $this->releaseOnOtherConnection = null;
                    $release();
                }
                return $invocation->proceed();
            }
        };
        $first = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, $interleaving::class],
            containerOrAvailableServices: [$handler, $interleaving, DbalConnectionFactory::class => $connection],
            extensionObjects: [SimpleMessageChannelBuilder::createPublishSubscribeChannel('collect.release.race.shared'), SimpleMessageChannelBuilder::createQueueChannel('release_race'), DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable($manualRelease ? '2026-01-01 12:00:00 UTC' : '2026-01-01 12:00:10 UTC'),
        );
        $second = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: [SimpleMessageChannelBuilder::createPublishSubscribeChannel('collect.release.race.shared'), SimpleMessageChannelBuilder::createQueueChannel('release_race'), DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $key = bin2hex(random_bytes(16));
        $second->sendCommandWithRouting('collect.release.race', ['key' => $key, 'value' => 'seed']);
        $second->run('release_race');
        $seed = array_values(array_filter($second->getGateway(GatheredGroups::class)->listOpenBatches('collect.release.race.handler'), static fn (array $batch): bool => $batch['groupKey'] === $key))[0];
        $interleaving->releaseOnOtherConnection = static function () use ($second, $seed, $manualRelease): void {
            if ($manualRelease) {
                $second->getGateway(GatheredGroups::class)->requestRelease($seed['groupId']);
            } else {
                $second->advanceTimeBy(new TimeSpan(seconds: 10));
            }
            $second->run('release_race', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 5)->withExecutionAmountLimit(5));
        };
        $first->sendCommandWithRouting('collect.release.race', ['key' => $key, 'value' => 'arriving']);
        $first->run('release_race', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 5)->withExecutionAmountLimit(5));
        self::assertCount(2, $interleaving->deliveryHeaders);
        self::assertSame($interleaving->deliveryHeaders[0][MessageHeaders::MESSAGE_ID], $interleaving->deliveryHeaders[1][MessageHeaders::MESSAGE_ID]);
        self::assertCount(1, $handler->batches);
        self::assertSame(['seed'], array_column(array_column($handler->batches[0]->messages, 'payload'), 'value'));
        self::assertSame($manualRelease ? CollectedReleaseReason::MANUAL_RELEASE : CollectedReleaseReason::MAX_WAIT_REACHED, $handler->batches[0]->releaseReason);
        $open = array_values(array_filter($first->getGateway(GatheredGroups::class)->listOpenBatches('collect.release.race.handler'), static fn (array $batch): bool => $batch['groupKey'] === $key));
        self::assertCount(1, $open);
        self::assertNotSame($seed['groupId'], $open[0]['groupId']);
        self::assertSame(1, $open[0]['recordedMessages']);
        $first->getGateway(GatheredGroups::class)->requestRelease($open[0]['groupId']);
        $first->run('release_race');
        self::assertCount(2, $handler->batches);
        self::assertSame(['arriving'], array_column(array_column($handler->batches[1]->messages, 'payload'), 'value'));
        if ($handlerKind !== 'command') {
            self::assertSame(['seed', 'arriving'], array_column($handler->ordinary, 'value'));
        }
    }

    public function test_max_size_plus_one_concurrent_messages_keep_the_next_partial_batch_in_a_real_database_race(): void
    {
        $this->proveConcurrentBatch(alreadyOpen: false, overflow: true);
    }

    private function proveConcurrentBatch(bool $alreadyOpen, bool $replayOriginals = false, bool $overflow = false): void
    {
        if ((new DbalConnectionFactory(getenv('DATABASE_DSN')))->createContext()->getDbalConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers with a database-wide lock, so this two-connection race is proved on PostgreSQL, MySQL and MariaDB only.');
        }
        $application = self::concurrentApplication();
        $application->run('collecting_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        $testId = bin2hex(random_bytes(16));
        $previousFailures = array_map(static fn ($failure): string => $failure->getMessageId(), $application->getGateway(DeadLetterGateway::class)->list(1000, 0));
        if ($alreadyOpen) {
            $application->sendCommandWithRouting('collect.concurrent', ['testId' => $testId, 'value' => 1], metadata: ['testId' => $testId]);
            $application->run('collecting_concurrency');
        }
        $originalIds = [];
        $messageCount = $overflow ? 9 : 8;
        foreach (range($alreadyOpen ? 2 : 1, $messageCount) as $value) {
            $originalIds[$value] = bin2hex(random_bytes(16));
            $application->sendCommandWithRouting('collect.concurrent', ['testId' => $testId, 'value' => $value], metadata: ['testId' => $testId, MessageHeaders::MESSAGE_ID => $originalIds[$value]]);
        }
        $this->runConcurrentConsumers(numberOfConsumers: $alreadyOpen ? $messageCount - 1 : $messageCount, testFile: __FILE__, consumerMethod: 'consumeConcurrentMessage');
        $application->run('collecting_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        $deadLetters = $application->getGateway(DeadLetterGateway::class);
        $failures = array_filter($deadLetters->list(1000, 0), static fn ($failure): bool => ! in_array($failure->getMessageId(), $previousFailures, true));
        foreach ($failures as $failure) {
            $deadLetters->replay($failure->getMessageId());
        }
        $application->run('collecting_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        self::assertSame([], array_values(array_filter($deadLetters->list(1000, 0), static fn ($failure): bool => ! in_array($failure->getMessageId(), $previousFailures, true))));

        if ($overflow) {
            $partial = array_values(array_filter($application->getGateway(GatheredGroups::class)->listOpenBatches('collect.concurrent.handler'), static fn (array $batch): bool => $batch['groupKey'] === $testId));
            self::assertCount(1, $partial);
            self::assertSame(1, $partial[0]['recordedMessages']);
            $application->getGateway(GatheredGroups::class)->requestRelease($partial[0]['groupId']);
            $application->run('collecting_concurrency');
        }

        if ($replayOriginals) {
            foreach ($originalIds as $value => $messageId) {
                $application->sendCommandWithRouting('collect.concurrent', ['testId' => $testId, 'value' => $value], metadata: ['testId' => $testId, MessageHeaders::MESSAGE_ID => $messageId]);
            }
            $application->run('collecting_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        }

        $results = array_values(array_filter($application->getGateway(DocumentStore::class)->getAllDocuments('collecting_concurrent_results'), static fn (string $document): bool => json_decode($document, true, flags: JSON_THROW_ON_ERROR)['testId'] === $testId));
        self::assertCount($overflow ? 2 : 1, $results);
        $values = [];
        foreach ($results as $document) {
            self::assertLessThanOrEqual(8, count(json_decode($document, true, flags: JSON_THROW_ON_ERROR)['values']));
            $values = [...$values, ...json_decode($document, true, flags: JSON_THROW_ON_ERROR)['values']];
        }
        sort($values);
        self::assertSame(range(1, $messageCount), $values);
        $open = array_filter($application->getGateway(GatheredGroups::class)->listOpenBatches('collect.concurrent.handler'), static fn (array $batch): bool => $batch['groupKey'] === $testId);
        self::assertSame([], $open);
        $closed = array_filter($application->getGateway(GatheredGroups::class)->listClosedBatches('collect.concurrent.handler'), static fn (array $batch): bool => $batch['groupKey'] === $testId);
        self::assertCount($overflow ? 2 : 1, $closed);
        $failures = array_filter($application->getGateway(DeadLetterGateway::class)->list(1000, 0), static fn ($failure): bool => ! in_array($failure->getMessageId(), $previousFailures, true));
        self::assertSame([], $failures);
        $deliveries = array_filter($application->getGateway(DocumentStore::class)->getAllDocuments('collecting_concurrent_deliveries'), static fn (string $document): bool => json_decode($document, true, flags: JSON_THROW_ON_ERROR)['testId'] === $testId);
        self::assertCount($replayOriginals ? $messageCount + count($originalIds) : $messageCount, $deliveries);
    }

    public static function consumeConcurrentMessage(): void
    {
        self::concurrentApplication([self::concurrentConsumerBarrier()])->run('collecting_concurrency', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false)->withExecutionAmountLimit(1));
    }

    private static function concurrentApplication(array $additionalServices = []): FlowTestSupport
    {
        $handler = new class () {
            #[Collected(maxSize: 8, groupBy: 'payload["testId"]')]
            #[Asynchronous('collecting_concurrency')]
            #[CommandHandler('collect.concurrent', endpointId: 'collect.concurrent.handler')]
            public function collect(CollectedMessages $batch, #[Reference] DocumentStore $documents): void
            {
                $documents->addDocument('collecting_concurrent_results', bin2hex(random_bytes(16)), json_encode(['testId' => $batch->groupKey, 'values' => array_column(array_column($batch->messages, 'payload'), 'value')], JSON_THROW_ON_ERROR));
            }
        };
        $deliveries = new class () {
            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 102, pointcut: AsynchronousRunningEndpoint::class)]
            public function recordDelivery(MethodInvocation $invocation, Message $message, #[Reference] DocumentStore $documents, #[Header('testId')] ?string $testId = null): mixed
            {
                $result = $invocation->proceed();
                if ($testId !== null) {
                    $documents->addDocument('collecting_concurrent_deliveries', bin2hex(random_bytes(16)), json_encode(['testId' => $testId, 'messageId' => $message->getHeaders()->getMessageId()], JSON_THROW_ON_ERROR));
                }
                return $result;
            }
        };
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, $deliveries::class, ...array_map(static fn (object $service): string => $service::class, $additionalServices)],
            containerOrAvailableServices: [$handler, $deliveries, ...$additionalServices, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: [DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false), DbalBackedMessageChannelBuilder::create('collecting_concurrency'), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter')],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults(),
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
    }
}
