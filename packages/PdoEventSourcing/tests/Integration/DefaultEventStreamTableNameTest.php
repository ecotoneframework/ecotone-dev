<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DefaultEventStreamTableNameTest extends EventSourcingMessagingTestCase
{
    public function test_an_aggregate_saved_under_a_configured_default_event_stream_table_name_loads_back(): void
    {
        $this->bootstrap($this->defaultConfiguration(), [$this->ledger()])
            ->sendCommand(new OpenLedgerForDefaultTableTest('ledger-1'))
            ->sendCommand(new DepositToLedgerForDefaultTableTest('ledger-1', 100));
        $ecotone = $this->bootstrap($this->ledgerEventsConfiguration(), [$this->ledger()]);

        $ecotone->sendCommand(new OpenLedgerForDefaultTableTest('ledger-1'));
        $ecotone->sendCommand(new DepositToLedgerForDefaultTableTest('ledger-1', 30));
        $ecotone->sendCommand(new DepositToLedgerForDefaultTableTest('ledger-1', 12));

        self::assertSame(42, $ecotone->sendQueryWithRouting('ledger.balance', metadata: ['aggregate.id' => 'ledger-1']));
    }

    public function test_events_of_the_default_stream_are_kept_in_the_configured_table_and_not_in_the_default_one(): void
    {
        $withLedgerTable = $this->bootstrap($this->ledgerEventsConfiguration(), [$this->ledger()]);
        $withDefaultTable = $this->bootstrap($this->defaultConfiguration(), []);

        $withLedgerTable->sendCommand(new OpenLedgerForDefaultTableTest('ledger-1'));

        self::assertCount(1, $withLedgerTable->getGateway(EventStore::class)->load('ecotone_event_stream'));
        self::assertCount(0, $withDefaultTable->getGateway(EventStore::class)->load('ecotone_event_stream'));
        self::assertCount(1, $this->bootstrap($this->ledgerEventsConfiguration(), [])->getGateway(EventStore::class)->load('ecotone_event_stream'));
    }

    public function test_with_events_seeds_the_configured_default_event_stream_table(): void
    {
        $this->bootstrap($this->defaultConfiguration(), [])->withEvents([new LedgerOpenedForDefaultTableTest('ledger-in-the-default-table')]);

        $this->bootstrap($this->ledgerEventsConfiguration(), [])->withEvents([new LedgerOpenedForDefaultTableTest('ledger-1')]);

        $seeded = $this->bootstrap($this->ledgerEventsConfiguration(), [])->getGateway(EventStore::class)->load('ecotone_event_stream');
        self::assertEquals([new LedgerOpenedForDefaultTableTest('ledger-1')], array_map(static fn ($event) => $event->getPayload(), $seeded));
    }

    public function test_a_projection_of_the_default_stream_reads_the_configured_table(): void
    {
        $this->bootstrap($this->defaultConfiguration(), [$this->ledger()])->sendCommand(new OpenLedgerForDefaultTableTest('ledger-in-the-default-table'));
        $writer = $this->bootstrap($this->ledgerEventsConfiguration(), [$this->ledger()]);
        $writer->sendCommand(new OpenLedgerForDefaultTableTest('ledger-1'));
        $writer->sendCommand(new OpenLedgerForDefaultTableTest('ledger-2'));

        $projection = new #[Projection('opened_ledgers'), FromStream('ecotone_event_stream')] class () {
            public array $openedLedgers = [];

            #[EventHandler]
            public function whenOpened(LedgerOpenedForDefaultTableTest $event): void
            {
                $this->openedLedgers[] = $event->ledgerId;
            }
        };
        $reader = $this->bootstrap($this->ledgerEventsConfiguration(), [$projection]);

        $reader->triggerProjection('opened_ledgers');

        self::assertSame(['ledger-1', 'ledger-2'], $projection->openedLedgers);
    }

    public function test_a_partitioned_projection_of_the_default_stream_reads_the_configured_table(): void
    {
        $this->bootstrap($this->defaultConfiguration(), [$this->ledger()])->sendCommand(new OpenLedgerForDefaultTableTest('ledger-in-the-default-table'));
        $writer = $this->bootstrap($this->ledgerEventsConfiguration(), [$this->ledger()]);
        $writer->sendCommand(new OpenLedgerForDefaultTableTest('ledger-1'));
        $writer->sendCommand(new OpenLedgerForDefaultTableTest('ledger-2'));

        $projection = new #[Projection('opened_ledgers_by_partition'), Partitioned, FromStream('ecotone_event_stream', 'ledger')] class () {
            public array $openedLedgers = [];

            #[EventHandler]
            public function whenOpened(LedgerOpenedForDefaultTableTest $event): void
            {
                $this->openedLedgers[] = $event->ledgerId;
            }
        };
        $reader = $this->bootstrap($this->ledgerEventsConfiguration(), [$projection]);

        $reader->triggerProjection('opened_ledgers_by_partition');

        sort($projection->openedLedgers);
        self::assertSame(['ledger-1', 'ledger-2'], $projection->openedLedgers);
    }

    public function test_database_setup_creates_the_configured_default_event_stream_table(): void
    {
        $ecotone = $this->bootstrap($this->ledgerEventsConfiguration(), [$this->ledger()], setUpTablesOnBootstrap: false);
        /** @var ConsoleCommandRunner $console */
        $console = $ecotone->getGateway(ConsoleCommandRunner::class);

        $createStatements = implode("\n", array_column($console->execute('ecotone:migration:database:setup', ['sql' => true])->getRows(), 0));
        self::assertStringContainsString('ledger_events', $createStatements);
        self::assertStringNotContainsString('ecotone_event_stream', $createStatements);

        $console->execute('ecotone:migration:database:setup', ['initialize' => true]);
        $ecotone->sendCommand(new OpenLedgerForDefaultTableTest('ledger-1'));

        self::assertSame(0, $ecotone->sendQueryWithRouting('ledger.balance', metadata: ['aggregate.id' => 'ledger-1']));
    }

    public function test_appending_before_the_configured_table_is_set_up_names_that_table(): void
    {
        $ecotone = $this->bootstrap($this->ledgerEventsConfiguration(), [$this->ledger()], setUpTablesOnBootstrap: false);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("The 'ledger_events' table required by the 'event_stream' feature does not exist.");

        $ecotone->sendCommand(new OpenLedgerForDefaultTableTest('ledger-1'));
    }

    private function ledgerEventsConfiguration(): object
    {
        return new class () {
            #[ServiceContext]
            public function eventSourcing(): EventSourcingConfiguration
            {
                return EventSourcingConfiguration::createWithDefaults()->withDefaultEventStreamTableName('ledger_events');
            }
        };
    }

    private function defaultConfiguration(): object
    {
        return new class () {
            #[ServiceContext]
            public function eventSourcing(): EventSourcingConfiguration
            {
                return EventSourcingConfiguration::createWithDefaults();
            }
        };
    }

    private function ledger(): object
    {
        return new #[EventSourcingAggregate(), AggregateType('ledger')] class () {
            use WithAggregateVersioning;

            #[Identifier]
            private string $ledgerId;
            private int $balance = 0;

            #[CommandHandler]
            public static function open(OpenLedgerForDefaultTableTest $command): array
            {
                return [new LedgerOpenedForDefaultTableTest($command->ledgerId)];
            }

            #[CommandHandler]
            public function deposit(DepositToLedgerForDefaultTableTest $command): array
            {
                return [new MoneyDepositedForDefaultTableTest($command->ledgerId, $command->amount)];
            }

            #[QueryHandler('ledger.balance')]
            public function balance(): int
            {
                return $this->balance;
            }

            #[Apply]
            public function whenOpened(LedgerOpenedForDefaultTableTest $event): void
            {
                $this->ledgerId = $event->ledgerId;
            }

            #[Apply]
            public function whenDeposited(MoneyDepositedForDefaultTableTest $event): void
            {
                $this->balance += $event->amount;
            }
        };
    }

    private function bootstrap(object $configuration, array $classes, bool $setUpTablesOnBootstrap = true): FlowTestSupport
    {
        $classes[] = $configuration;
        $classes[] = new class () {
            #[Converter]
            public function fromLedgerOpened(LedgerOpenedForDefaultTableTest $event): array
            {
                return ['ledgerId' => $event->ledgerId];
            }

            #[Converter]
            public function toLedgerOpened(array $payload): LedgerOpenedForDefaultTableTest
            {
                return new LedgerOpenedForDefaultTableTest($payload['ledgerId']);
            }

            #[Converter]
            public function fromMoneyDeposited(MoneyDepositedForDefaultTableTest $event): array
            {
                return ['ledgerId' => $event->ledgerId, 'amount' => $event->amount];
            }

            #[Converter]
            public function toMoneyDeposited(array $payload): MoneyDepositedForDefaultTableTest
            {
                return new MoneyDepositedForDefaultTableTest($payload['ledgerId'], $payload['amount']);
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: array_map(static fn (object $class) => $class::class, $classes),
            containerOrAvailableServices: [...$classes, DbalConnectionFactory::class => self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE]),
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap($setUpTablesOnBootstrap),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

final readonly class OpenLedgerForDefaultTableTest
{
    public function __construct(public string $ledgerId)
    {
    }
}

final readonly class DepositToLedgerForDefaultTableTest
{
    public function __construct(public string $ledgerId, public int $amount)
    {
    }
}

final readonly class LedgerOpenedForDefaultTableTest
{
    public function __construct(public string $ledgerId)
    {
    }
}

final readonly class MoneyDepositedForDefaultTableTest
{
    public function __construct(public string $ledgerId, public int $amount)
    {
    }
}
