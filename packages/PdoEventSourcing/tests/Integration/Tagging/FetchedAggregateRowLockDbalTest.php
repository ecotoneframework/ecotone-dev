<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Throwable;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class FetchedAggregateRowLockDbalTest extends EventSourcingMessagingTestCase
{
    public function test_a_save_of_a_fetched_aggregate_on_another_connection_waits_until_the_deciding_transaction_commits(): void
    {
        $seats = $this->seatHolding();
        $anna = $this->bootstrap(self::getConnectionFactory(), $seats);
        $benConnectionFactory = $this->competingConnectionFactory();
        $ben = $this->bootstrap($benConnectionFactory, $this->seatHolding());
        $anna->sendCommandWithRouting('concert.schedule', 'c-1');
        $refusedWhileDeciding = null;
        $seats->afterAppending(function () use ($ben, &$refusedWhileDeciding): void {
            try {
                $ben->sendCommandWithRouting('concert.rename', ['concertId' => 'c-1', 'name' => 'Encore']);
            } catch (Throwable $refused) {
                $refusedWhileDeciding = $refused;
            }
        });

        $anna->sendCommand(new HoldSeatForRowLockTest('c-1', 'A1'));
        $ben->sendCommandWithRouting('concert.rename', ['concertId' => 'c-1', 'name' => 'Encore']);

        self::assertInstanceOf(ConcurrencyException::class, $refusedWhileDeciding);
        self::assertSame(['A1'], $this->heldSeatsOf($anna));
        self::assertSame('Encore', $anna->sendQueryWithRouting('concert.name', metadata: ['aggregate.id' => 'c-1']));
    }

    public function test_a_second_decision_fetching_the_same_aggregate_on_another_connection_waits_for_the_first_to_commit_then_succeeds(): void
    {
        if (self::getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->markTestSkipped('SQLite lets one connection write at a time, so a second decision waits for the first whatever it fetched.');
        }

        $seats = $this->seatHolding();
        $anna = $this->bootstrap(self::getConnectionFactory(), $seats);
        $ben = $this->bootstrap($this->competingConnectionFactory(), $this->seatHolding());
        $anna->sendCommandWithRouting('concert.schedule', 'c-1');
        $refusedWhileDeciding = null;
        $seats->afterAppending(function () use ($ben, &$refusedWhileDeciding): void {
            try {
                $ben->sendCommand(new HoldSeatForRowLockTest('c-1', 'A2'));
            } catch (Throwable $refused) {
                $refusedWhileDeciding = $refused;
            }
        });

        $anna->sendCommand(new HoldSeatForRowLockTest('c-1', 'A1'));
        $ben->sendCommand(new HoldSeatForRowLockTest('c-1', 'A2'));

        self::assertInstanceOf(ConcurrencyException::class, $refusedWhileDeciding);
        self::assertSame(['A1', 'A2'], $this->heldSeatsOf($anna));
    }

    private function competingConnectionFactory(): DbalConnectionFactory
    {
        $connectionFactory = new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone');
        $connection = $connectionFactory->createContext()->getDbalConnection();
        $connection->executeStatement($this->shortLockWaitOf($connection));

        return $connectionFactory;
    }

    private function shortLockWaitOf(Connection $connection): string
    {
        return match (true) {
            $connection->getDatabasePlatform() instanceof PostgreSQLPlatform => "SET lock_timeout = '200ms'",
            $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform => 'SET SESSION innodb_lock_wait_timeout = 1',
            $connection->getDatabasePlatform() instanceof SQLitePlatform => 'PRAGMA busy_timeout = 200',
        };
    }

    private function seatHolding(): object
    {
        return new class () {
            private ?Closure $afterAppending = null;

            public function afterAppending(Closure $afterAppending): void
            {
                $this->afterAppending = $afterAppending;
            }

            #[CommandHandler]
            public function hold(
                HoldSeatForRowLockTest $command,
                #[Fetch('payload.concertId')] ConcertForRowLockTest $concert,
                SeatHoldsForRowLockTest $seat,
            ): array {
                return $seat->held ? [] : [new SeatHeldForRowLockTest($command->concertId, $command->seatId)];
            }

            #[EventHandler]
            public function whenHeld(SeatHeldForRowLockTest $event): void
            {
                $afterAppending = $this->afterAppending;
                $this->afterAppending = null;
                if ($afterAppending !== null) {
                    $afterAppending();
                }
            }
        };
    }

    private function bootstrap(DbalConnectionFactory $connectionFactory, object $seats): FlowTestSupport
    {
        $converter = new class () {
            #[Converter]
            public function fromScheduled(ConcertScheduledForRowLockTest $event): array
            {
                return ['concertId' => $event->concertId];
            }

            #[Converter]
            public function toScheduled(array $event): ConcertScheduledForRowLockTest
            {
                return new ConcertScheduledForRowLockTest($event['concertId']);
            }

            #[Converter]
            public function fromRenamed(ConcertRenamedForRowLockTest $event): array
            {
                return ['concertId' => $event->concertId, 'name' => $event->name];
            }

            #[Converter]
            public function toRenamed(array $event): ConcertRenamedForRowLockTest
            {
                return new ConcertRenamedForRowLockTest($event['concertId'], $event['name']);
            }

            #[Converter]
            public function fromSeatHeld(SeatHeldForRowLockTest $event): array
            {
                return ['concertId' => $event->concertId, 'seatId' => $event->seatId];
            }

            #[Converter]
            public function toSeatHeld(array $event): SeatHeldForRowLockTest
            {
                return new SeatHeldForRowLockTest($event['concertId'], $event['seatId']);
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [
                ConcertForRowLockTest::class, ConcertScheduledForRowLockTest::class, ConcertRenamedForRowLockTest::class,
                SeatHoldsForRowLockTest::class, SeatHeldForRowLockTest::class, $converter::class, $seats::class,
            ],
            containerOrAvailableServices: [$converter, $seats, DbalConnectionFactory::class => $connectionFactory],
            extensionObjects: [DynamicConsistencyBoundaryConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();
    }

    /**
     * @return string[]
     */
    private function heldSeatsOf(FlowTestSupport $ecotone): array
    {
        return array_map(
            static fn (Event $event): string => $event->getPayload()->seatId,
            $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('seat', 'A1')->or(EventCriteria::tag('seat', 'A2')))->events,
        );
    }
}

final readonly class HoldSeatForRowLockTest
{
    public function __construct(
        public string $concertId,
        public string $seatId,
    ) {
    }
}

final readonly class ConcertScheduledForRowLockTest
{
    public function __construct(public string $concertId)
    {
    }
}

final readonly class ConcertRenamedForRowLockTest
{
    public function __construct(
        public string $concertId,
        public string $name,
    ) {
    }
}

final readonly class SeatHeldForRowLockTest
{
    public function __construct(
        public string $concertId,
        #[EventTag('seat')] public string $seatId,
    ) {
    }
}

#[EventSourcingAggregate]
#[AggregateType('concert')]
final class ConcertForRowLockTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $concertId;

    private string $name = '';

    #[CommandHandler('concert.schedule')]
    public static function schedule(string $concertId): array
    {
        return [new ConcertScheduledForRowLockTest($concertId)];
    }

    #[CommandHandler('concert.rename')]
    public function rename(array $command): array
    {
        return [new ConcertRenamedForRowLockTest($this->concertId, $command['name'])];
    }

    #[QueryHandler('concert.name')]
    public function name(): string
    {
        return $this->name;
    }

    #[Apply]
    public function scheduled(ConcertScheduledForRowLockTest $event): void
    {
        $this->concertId = $event->concertId;
    }

    #[Apply]
    public function renamed(ConcertRenamedForRowLockTest $event): void
    {
        $this->name = $event->name;
    }
}

#[DecisionModel(tags: ['seat'])]
final class SeatHoldsForRowLockTest
{
    public bool $held = false;

    #[Apply]
    public function held(SeatHeldForRowLockTest $event): void
    {
        $this->held = true;
    }
}
