<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\TransactionIsolationLevel;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Amqp\AmqpConnectionReference;
use Ecotone\Api\Amqp\AmqpLibConnectionFactory;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Messaging\CollectedReleaseReason;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

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

    public function test_consumers_opening_the_same_open_slot_in_a_real_database_race_keep_one_batch_and_resend_the_loser(): void
    {
        $this->proveConcurrentBatch(false);
    }


    public function test_original_message_redelivery_after_a_real_database_conflict_is_not_collected_twice(): void
    {
        $this->proveConcurrentBatch(alreadyOpen: false, replayOriginals: true);
    }

    public function test_original_message_redelivery_after_a_real_database_conflict_on_a_non_transactional_channel_is_not_collected_twice(): void
    {
        if (! class_exists(AmqpBackedMessageChannelBuilder::class)) {
            self::markTestSkipped('The non-transactional conflict path requires the optional AMQP package.');
        }
        $this->proveConcurrentBatch(alreadyOpen: false, replayOriginals: true, nonTransactional: true);
    }

    public function test_cleanup_racing_two_arriving_messages_keeps_one_open_batch_in_a_real_database_race(): void
    {
        $this->proveOpenSlotRace(cleanup: true);
    }

    public function test_discard_racing_an_arriving_message_frees_the_slot_atomically_in_a_real_database_race(): void
    {
        $this->proveOpenSlotRace(cleanup: false);
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

    private function proveReleaseRace(bool $manualRelease): void
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
        $interleaving = new class () {
            public ?\Closure $releaseOnOtherConnection = null;

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 101, pointcut: AsynchronousRunningEndpoint::class)]
            public function interleave(MethodInvocation $invocation, #[Reference] DocumentStore $documents): mixed
            {
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
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('release_race'), DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable($manualRelease ? '2026-01-01 12:00:00 UTC' : '2026-01-01 12:00:10 UTC'),
        );
        $second = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('release_race'), DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false)],
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
    }

    public function test_max_size_plus_one_concurrent_messages_keep_the_next_partial_batch_in_a_real_database_race(): void
    {
        $this->proveConcurrentBatch(alreadyOpen: false, overflow: true);
    }

    private function proveConcurrentBatch(bool $alreadyOpen, bool $replayOriginals = false, bool $nonTransactional = false, bool $overflow = false): void
    {
        if ((new DbalConnectionFactory(getenv('DATABASE_DSN')))->createContext()->getDbalConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers with a database-wide lock, so this two-connection race is proved on PostgreSQL, MySQL and MariaDB only.');
        }
        $application = self::concurrentApplication(nonTransactional: $nonTransactional);
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
        $this->runConcurrentConsumers(numberOfConsumers: $alreadyOpen ? $messageCount - 1 : $messageCount, testFile: __FILE__, consumerMethod: $nonTransactional ? 'consumeConcurrentMessageFromNonTransactionalChannel' : 'consumeConcurrentMessage');
        $application->run('collecting_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

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
        self::assertGreaterThan($messageCount, count($deliveries));
    }

    public static function consumeConcurrentMessage(): void
    {
        self::concurrentApplication([self::concurrentConsumerBarrier()])->run('collecting_concurrency', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false)->withExecutionAmountLimit(1));
    }

    public static function consumeConcurrentMessageFromNonTransactionalChannel(): void
    {
        self::concurrentApplication(additionalServices: [self::concurrentConsumerBarrier()], nonTransactional: true)->run('collecting_concurrency', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false)->withExecutionAmountLimit(1));
    }

    private static function concurrentApplication(array $additionalServices = [], bool $nonTransactional = false): FlowTestSupport
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
            containerOrAvailableServices: [$handler, $deliveries, ...$additionalServices, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN')), ...($nonTransactional ? [AmqpConnectionReference::defaultConnection()->getReferenceName() => new AmqpLibConnectionFactory(getenv('RABBIT_HOST'))] : [])],
            extensionObjects: [DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false), ($nonTransactional ? AmqpBackedMessageChannelBuilder::create('collecting_concurrency') : DbalBackedMessageChannelBuilder::create('collecting_concurrency')), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter')],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ...($nonTransactional ? [ModulePackageList::AMQP_PACKAGE] : [])],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
    }
}
