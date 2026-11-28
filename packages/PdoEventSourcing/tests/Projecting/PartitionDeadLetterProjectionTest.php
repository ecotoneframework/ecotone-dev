<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\PartitionDeadLetter;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\PartitionAggregateId;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionBackfill;
use Ecotone\Api\Projecting\ProjectionRebuild;
use Ecotone\Api\Projecting\ProjectionReset;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class PartitionDeadLetterProjectionTest extends ProjectingTestCase
{
    private const CHANNEL = 'partitionHeld.projections';
    private const WALLET_ONE = 'ecotone_event_stream:' . PartitionHeldWallet::class . ':1';

    public function test_a_partitioned_asynchronous_projection_holds_the_triggers_of_the_aggregate_whose_event_failed_while_another_aggregate_keeps_projecting(): void
    {
        $credits = $this->walletCredits();
        $ecotone = $this->bootstrap([$credits]);
        $this->openWallets($ecotone, '1', '2');
        $this->credit($ecotone, '1', -5);
        $this->consume($ecotone);

        $credits->refusesNegativeCredits = false;
        $this->credit($ecotone, '1', 10);
        $this->credit($ecotone, '2', 20);
        $this->consume($ecotone);

        self::assertSame(['2:20'], $this->credited());
        self::assertSame(
            [[self::WALLET_ONE, 1], [self::WALLET_ONE, 2]],
            array_map(static fn (ErrorContext $held): array => [$held->partitionKey, $held->partitionPosition], $this->held($ecotone)),
        );
    }

    public function test_replaying_the_oldest_trigger_catches_the_held_partition_up_from_its_position_and_the_following_triggers_project_nothing_more(): void
    {
        $credits = $this->walletCredits();
        $ecotone = $this->bootstrap([$credits]);
        $this->openWallets($ecotone, '1');
        $this->credit($ecotone, '1', 5);
        $this->consume($ecotone);
        $this->credit($ecotone, '1', -5);
        $this->credit($ecotone, '1', 10);
        $this->credit($ecotone, '1', 30);
        $this->consume($ecotone);
        self::assertSame(['1:5'], $this->credited());

        $credits->refusesNegativeCredits = false;
        $ecotone->getGateway(DeadLetterGateway::class)->replay($this->held($ecotone)[0]->getMessageId());
        $this->consume($ecotone);

        self::assertSame(['1:-5', '1:5', '1:10', '1:30'], $this->credited());
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_a_replayed_trigger_that_fails_again_keeps_its_partition_held_under_the_tracking_name_of_the_attribute(): void
    {
        $credits = new #[Projection('partition_held_wallet_credits'), FromAggregateStream(PartitionHeldWallet::class), Partitioned, Asynchronous('partitionHeld.projections'), PartitionDeadLetter(trackingName: 'wallet_read_models')] class () {
            #[EventHandler]
            public function whenCredited(PartitionHeldWalletCredited $event, #[CurrentConnection] Connection $connection): void
            {
                if ($event->amount < 0) {
                    throw new RuntimeException('balance refuses a negative credit');
                }

                $connection->executeStatement('INSERT INTO partition_held_wallet_credits (wallet_id, amount) VALUES (?, ?)', [$event->walletId, $event->amount]);
            }
        };
        $ecotone = $this->bootstrap([$credits]);
        $this->openWallets($ecotone, '1');
        $this->credit($ecotone, '1', -5);
        $this->credit($ecotone, '1', 10);
        $this->consume($ecotone);
        $held = $this->heldAs($ecotone);

        $ecotone->getGateway(DeadLetterGateway::class)->replay($this->held($ecotone)[0]->getMessageId());
        $this->consume($ecotone);

        self::assertSame([['wallet_read_models', self::WALLET_ONE, 1], ['wallet_read_models', self::WALLET_ONE, 2]], $held);
        self::assertSame($held, $this->heldAs($ecotone));
        self::assertSame([], $this->credited());
    }

    public function test_replaying_a_later_trigger_of_a_held_partition_starts_the_partition_from_its_oldest_trigger(): void
    {
        $credits = $this->walletCredits();
        $ecotone = $this->bootstrap([$credits]);
        $this->holdWalletOneWithTwoTriggers($ecotone);

        $credits->refusesNegativeCredits = false;
        $ecotone->getGateway(DeadLetterGateway::class)->replay($this->held($ecotone)[1]->getMessageId());
        $this->consume($ecotone);

        self::assertSame(['1:-5', '1:10'], $this->credited());
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_a_later_trigger_replayed_ignoring_the_partition_order_catches_the_partition_up_and_leaves_the_older_trigger_held(): void
    {
        $credits = $this->walletCredits();
        $ecotone = $this->bootstrap([$credits]);
        $this->holdWalletOneWithTwoTriggers($ecotone);
        [$oldest, $later] = $this->held($ecotone);

        $credits->refusesNegativeCredits = false;
        $ecotone->getGateway(DeadLetterGateway::class)->replay($later->getMessageId(), ignorePartitionOrder: true);
        $this->consume($ecotone);
        self::assertSame(['1:-5', '1:10'], $this->credited());
        self::assertSame([$oldest->getMessageId()], array_map(static fn (ErrorContext $held): string => $held->getMessageId(), $this->held($ecotone)));

        $ecotone->getGateway(DeadLetterGateway::class)->replay($oldest->getMessageId());
        $this->consume($ecotone);

        self::assertSame(['1:-5', '1:10'], $this->credited());
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_deleting_the_triggers_of_a_held_partition_lets_the_next_trigger_catch_it_up(): void
    {
        $credits = $this->walletCredits();
        $ecotone = $this->bootstrap([$credits]);
        $this->holdWalletOneWithTwoTriggers($ecotone);

        $credits->refusesNegativeCredits = false;
        $ecotone->getGateway(DeadLetterGateway::class)->delete(array_map(static fn (ErrorContext $held): string => $held->getMessageId(), $this->held($ecotone)));
        $this->consume($ecotone);
        self::assertSame([], $this->credited());
        $this->credit($ecotone, '1', 30);
        $this->consume($ecotone);

        self::assertSame(['1:-5', '1:10', '1:30'], $this->credited());
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_a_held_partition_of_a_projection_is_replayed_from_the_console(): void
    {
        $credits = $this->walletCredits();
        $ecotone = $this->bootstrap([$credits]);
        $this->holdWalletOneWithTwoTriggers($ecotone);

        $credits->refusesNegativeCredits = false;
        $ecotone->runConsoleCommand('ecotone:deadletter:replay', ['messageId' => $this->held($ecotone)[1]->getMessageId()]);
        $this->consume($ecotone);

        self::assertSame(['1:-5', '1:10'], $this->credited());
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_the_console_listing_names_the_partition_a_trigger_of_a_projection_is_held_under(): void
    {
        $ecotone = $this->bootstrap([$this->walletCredits()]);
        $this->holdWalletOneWithTwoTriggers($ecotone);

        $listing = $ecotone->resultOfConsoleCommand('ecotone:deadletter:list');

        self::assertContains('Partition Key', $listing->columnHeaders);
        self::assertSame([self::WALLET_ONE, self::WALLET_ONE], array_column($listing->rows, array_search('Partition Key', $listing->columnHeaders, true)));
    }

    public function test_a_projection_sending_its_failed_triggers_to_the_dead_letter_through_its_own_error_channel_holds_and_replays_its_partition(): void
    {
        $credits = new #[Projection('partition_held_wallet_credits'), FromAggregateStream(PartitionHeldWallet::class), Partitioned, Asynchronous('partitionHeld.projections', asynchronousExecution: [new ErrorChannel('dbal_dead_letter')]), PartitionDeadLetter(trackingName: 'wallet_credits')] class () {
            public bool $refusesNegativeCredits = true;

            #[EventHandler]
            public function whenCredited(PartitionHeldWalletCredited $event, #[CurrentConnection] Connection $connection): void
            {
                if ($this->refusesNegativeCredits && $event->amount < 0) {
                    throw new RuntimeException('balance refuses a negative credit');
                }

                $connection->executeStatement('INSERT INTO partition_held_wallet_credits (wallet_id, amount) VALUES (?, ?)', [$event->walletId, $event->amount]);
            }
        };
        $ecotone = $this->bootstrap([$credits], errorChannel: 'partitionHeld.unrelatedErrors');
        $this->holdWalletOneWithTwoTriggers($ecotone);
        self::assertSame([['wallet_credits', self::WALLET_ONE, 1], ['wallet_credits', self::WALLET_ONE, 2]], $this->heldAs($ecotone));

        $credits->refusesNegativeCredits = false;
        $ecotone->getGateway(DeadLetterGateway::class)->replay($this->held($ecotone)[0]->getMessageId());
        $this->consume($ecotone);

        self::assertSame(['1:-5', '1:10'], $this->credited());
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_a_projection_retried_with_a_delay_holds_its_partition_once_the_retries_are_used_up_and_releases_it_when_a_replay_passes(): void
    {
        $credits = new #[Projection('partition_held_wallet_credits'), FromAggregateStream(PartitionHeldWallet::class), Partitioned, Asynchronous('partitionHeld.projections'), PartitionDeadLetter(trackingName: 'wallet_credits')] class () {
            public bool $refusesNegativeCredits = true;

            #[EventHandler]
            public function whenCredited(PartitionHeldWalletCredited $event, #[CurrentConnection] Connection $connection): void
            {
                if ($this->refusesNegativeCredits && $event->amount < 0) {
                    throw new RuntimeException('balance refuses a negative credit');
                }

                $connection->executeStatement('INSERT INTO partition_held_wallet_credits (wallet_id, amount) VALUES (?, ?)', [$event->walletId, $event->amount]);
            }
        };
        $ecotone = $this->bootstrap(
            [$credits],
            [ErrorHandlerConfiguration::createWithDeadLetterChannel(errorChannelName: 'partitionHeld.errors', delayedRetryTemplate: RetryTemplateBuilder::fixedBackOff(1000)->maxRetries(1), deadLetterChannel: 'dbal_dead_letter')],
            errorChannel: 'partitionHeld.errors',
            initialTime: new DateTimeImmutable('2026-11-26 10:00:00 UTC'),
        );
        $this->openWallets($ecotone, '1');
        $this->credit($ecotone, '1', -5);
        $this->consume($ecotone);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
        $ecotone->advanceTimeBy(Duration::seconds(2));
        $this->consume($ecotone);
        $this->credit($ecotone, '1', 10);
        $this->consume($ecotone);
        self::assertSame(2, $ecotone->getGateway(DeadLetterGateway::class)->count());

        $credits->refusesNegativeCredits = false;
        $ecotone->getGateway(DeadLetterGateway::class)->replay($this->held($ecotone)[0]->getMessageId());
        $this->consume($ecotone);

        self::assertSame(['1:-5', '1:10'], $this->credited());
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public static function runsBesideTheTriggers(): iterable
    {
        yield 'rebuild' => [static fn (FlowTestSupport $ecotone) => $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'partition_held_wallet_credits'])];
        yield 'backfill' => [static fn (FlowTestSupport $ecotone) => $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => 'partition_held_wallet_credits'])];
        yield 'manual trigger' => [static fn (FlowTestSupport $ecotone) => $ecotone->triggerProjection('partition_held_wallet_credits')];
    }

    #[DataProvider('runsBesideTheTriggers')]
    public function test_a_run_beside_the_triggers_projects_a_held_partition_and_its_triggers_replayed_afterwards_project_nothing_more(callable $run): void
    {
        $credits = new #[Projection('partition_held_wallet_credits'), FromAggregateStream(PartitionHeldWallet::class), Partitioned, Asynchronous('partitionHeld.projections'), ProjectionRebuild, ProjectionBackfill, PartitionDeadLetter(trackingName: 'wallet_credits')] class () {
            public bool $refusesNegativeCredits = true;

            #[EventHandler]
            public function whenCredited(PartitionHeldWalletCredited $event, #[CurrentConnection] Connection $connection): void
            {
                if ($this->refusesNegativeCredits && $event->amount < 0) {
                    throw new RuntimeException('balance refuses a negative credit');
                }

                $connection->executeStatement('INSERT INTO partition_held_wallet_credits (wallet_id, amount) VALUES (?, ?)', [$event->walletId, $event->amount]);
            }

            #[ProjectionReset]
            public function reset(#[PartitionAggregateId] string $walletId, #[CurrentConnection] Connection $connection): void
            {
                $connection->executeStatement('DELETE FROM partition_held_wallet_credits WHERE wallet_id = ?', [$walletId]);
            }
        };
        $ecotone = $this->bootstrap([$credits]);
        $this->holdWalletOneWithTwoTriggers($ecotone);

        $credits->refusesNegativeCredits = false;
        $run($ecotone);
        self::assertSame(['1:-5', '1:10'], $this->credited());
        self::assertSame(2, $ecotone->getGateway(DeadLetterGateway::class)->count());
        $ecotone->getGateway(DeadLetterGateway::class)->replay($this->held($ecotone)[0]->getMessageId());
        $this->consume($ecotone);

        self::assertSame(['1:-5', '1:10'], $this->credited());
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_projections_sharing_a_tracking_name_hold_the_partition_together(): void
    {
        $credits = new #[Projection('partition_held_wallet_credits'), FromAggregateStream(PartitionHeldWallet::class), Partitioned, Asynchronous('partitionHeld.projections'), PartitionDeadLetter(trackingName: 'wallet_read_models')] class () {
            #[EventHandler]
            public function whenCredited(PartitionHeldWalletCredited $event, #[CurrentConnection] Connection $connection): void
            {
                if ($event->amount < 0) {
                    throw new RuntimeException('balance refuses a negative credit');
                }

                $connection->executeStatement('INSERT INTO partition_held_wallet_credits (wallet_id, amount) VALUES (?, ?)', [$event->walletId, $event->amount]);
            }
        };
        $statements = new #[Projection('partition_held_wallet_statements'), FromAggregateStream(PartitionHeldWallet::class), Partitioned, Asynchronous('partitionHeld.statements'), PartitionDeadLetter(trackingName: 'wallet_read_models')] class () {
            public array $printed = [];

            #[EventHandler]
            public function whenCredited(PartitionHeldWalletCredited $event): void
            {
                $this->printed[] = "{$event->walletId}:{$event->amount}";
            }
        };
        $ecotone = $this->bootstrap([$credits, $statements], [DbalBackedMessageChannelBuilder::create('partitionHeld.statements')]);
        $this->openWallets($ecotone, '1', '2');
        $this->credit($ecotone, '1', -5);
        $this->consume($ecotone);

        $this->credit($ecotone, '2', 20);
        $ecotone->run('partitionHeld.statements', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        self::assertSame(['2:20'], $statements->printed);
        self::assertSame([['wallet_read_models', self::WALLET_ONE, 1], ['wallet_read_models', self::WALLET_ONE, 2]], $this->heldAs($ecotone));
    }

    private function walletCredits(): object
    {
        return new #[Projection('partition_held_wallet_credits'), FromAggregateStream(PartitionHeldWallet::class), Partitioned, Asynchronous('partitionHeld.projections'), PartitionDeadLetter] class () {
            public bool $refusesNegativeCredits = true;

            #[EventHandler]
            public function whenCredited(PartitionHeldWalletCredited $event, #[CurrentConnection] Connection $connection): void
            {
                if ($this->refusesNegativeCredits && $event->amount < 0) {
                    throw new RuntimeException('balance refuses a negative credit');
                }

                $connection->executeStatement('INSERT INTO partition_held_wallet_credits (wallet_id, amount) VALUES (?, ?)', [$event->walletId, $event->amount]);
            }
        };
    }

    private function held(FlowTestSupport $ecotone): array
    {
        $held = array_values(array_filter(
            $ecotone->getGateway(DeadLetterGateway::class)->list(100, 0),
            static fn (ErrorContext $errorContext): bool => $errorContext->partitionKey !== null,
        ));
        usort($held, static fn (ErrorContext $one, ErrorContext $other): int => $one->partitionPosition <=> $other->partitionPosition);

        return $held;
    }

    private function heldAs(FlowTestSupport $ecotone): array
    {
        return array_map(static fn (ErrorContext $held): array => [$held->partitionTrackingName, $held->partitionKey, $held->partitionPosition], $this->held($ecotone));
    }

    private function holdWalletOneWithTwoTriggers(FlowTestSupport $ecotone): void
    {
        $this->openWallets($ecotone, '1');
        $this->credit($ecotone, '1', -5);
        $this->credit($ecotone, '1', 10);
        $this->consume($ecotone);
    }

    private function openWallets(FlowTestSupport $ecotone, string ...$walletIds): void
    {
        foreach ($walletIds as $walletId) {
            $ecotone->sendCommandWithRouting('partitionHeld.wallet.open', $walletId);
        }
    }

    private function credit(FlowTestSupport $ecotone, string $walletId, int $amount): void
    {
        $ecotone->sendCommandWithRouting('partitionHeld.wallet.credit', $amount, metadata: ['aggregate.id' => $walletId]);
    }

    private function consume(FlowTestSupport $ecotone): void
    {
        $ecotone->run(self::CHANNEL, ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
    }

    private function credited(): array
    {
        return array_map(
            static fn (array $credit): string => "{$credit['wallet_id']}:{$credit['amount']}",
            $this->getConnection()->fetchAllAssociative('SELECT wallet_id, amount FROM partition_held_wallet_credits ORDER BY wallet_id, amount'),
        );
    }

    private function bootstrap(array $projections, array $extensionObjects = [], string $errorChannel = 'dbal_dead_letter', ?DateTimeImmutable $initialTime = null): FlowTestSupport
    {
        $this->getConnection()->executeStatement('DROP TABLE IF EXISTS partition_held_wallet_credits');
        $this->getConnection()->executeStatement('CREATE TABLE partition_held_wallet_credits (wallet_id VARCHAR(36) NOT NULL, amount INT NOT NULL)');
        $converter = new class () {
            #[Converter]
            public function fromOpened(PartitionHeldWalletOpened $event): array
            {
                return ['walletId' => $event->walletId];
            }

            #[Converter]
            public function toOpened(array $event): PartitionHeldWalletOpened
            {
                return new PartitionHeldWalletOpened($event['walletId']);
            }

            #[Converter]
            public function fromCredited(PartitionHeldWalletCredited $event): array
            {
                return ['walletId' => $event->walletId, 'amount' => $event->amount];
            }

            #[Converter]
            public function toCredited(array $event): PartitionHeldWalletCredited
            {
                return new PartitionHeldWalletCredited($event['walletId'], $event['amount']);
            }
        };

        return $this->bootstrapFlowTesting(
            classesToResolve: [PartitionHeldWallet::class, $converter::class, ...array_map(static fn (object $projection): string => $projection::class, $projections)],
            containerOrAvailableServices: [...$projections, $converter, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create(self::CHANNEL),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel($errorChannel),
                ...$extensionObjects,
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            initialTime: $initialTime,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

final class PartitionHeldWalletOpened
{
    public function __construct(public readonly string $walletId)
    {
    }
}

final class PartitionHeldWalletCredited
{
    public function __construct(public readonly string $walletId, public readonly int $amount)
    {
    }
}

#[EventSourcingAggregate]
final class PartitionHeldWallet
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $walletId;

    #[CommandHandler('partitionHeld.wallet.open')]
    public static function open(string $walletId): array
    {
        return [new PartitionHeldWalletOpened($walletId)];
    }

    #[CommandHandler('partitionHeld.wallet.credit')]
    public function credit(int $amount): array
    {
        return [new PartitionHeldWalletCredited($this->walletId, $amount)];
    }

    #[Apply]
    public function whenOpened(PartitionHeldWalletOpened $event): void
    {
        $this->walletId = $event->walletId;
    }
}
