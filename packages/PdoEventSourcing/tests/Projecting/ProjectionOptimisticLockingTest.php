<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * licence Apache-2.0
 * @internal
 */
final class ProjectionOptimisticLockingTest extends ProjectingTestCase
{
    public const NAME = 'optimistic_tickets_projection';
    public const CHANNEL = 'optimistic_tickets_channel';

    public function setUp(): void
    {
        parent::setUp();

        $platform = self::getConnection()->getDatabasePlatform();
        if (! ($platform instanceof PostgreSQLPlatform || $platform instanceof AbstractMySQLPlatform)) {
            $this->markTestSkipped('SQLite cannot commit from a second connection while the first holds a write transaction.');
        }

        self::getConnection()->executeStatement('DROP TABLE IF EXISTS optimistic_projected_tickets');
        self::getConnection()->executeStatement('CREATE TABLE optimistic_projected_tickets (ticket_id VARCHAR(36) NOT NULL)');
    }

    public function test_a_synchronous_projection_run_that_another_run_overtook_fails_with_a_concurrency_exception_and_projects_nothing(): void
    {
        [$anna, $annaProjection] = $this->bootstrap(self::getConnectionFactory(), asynchronous: false);
        [$ben] = $this->bootstrap($this->secondConnectionFactory(), asynchronous: false);
        $anna->deleteProjection(self::NAME)->initializeProjection(self::NAME);
        $annaProjection->arm(fn () => $ben->sendCommand(new RegisterTicket('ticket-ben', 'Ben', 'alert')));

        try {
            $anna->sendCommand(new RegisterTicket('ticket-anna', 'Anna', 'alert'));
            self::fail('Expected a ConcurrencyException');
        } catch (ConcurrencyException $exception) {
            self::assertStringContainsString(
                "Projection 'optimistic_tickets_projection' (partition: global) was loaded at version 0, but another run of the same partition saved it first.",
                $exception->getMessage(),
            );
            self::assertStringContainsString('the command bus and asynchronous endpoints retry by default when DbalConfiguration runs them in a database transaction', $exception->getMessage());
        }

        self::assertSame(['ticket-ben'], $anna->sendQueryWithRouting('optimistic.projectedTickets'));
    }

    public function test_a_synchronous_projection_run_that_another_run_overtook_is_retried_by_default_and_projects_every_event_once(): void
    {
        [$anna, $annaProjection] = $this->bootstrap(self::getConnectionFactory(), asynchronous: false, defaultInstantRetries: true);
        [$ben] = $this->bootstrap($this->secondConnectionFactory(), asynchronous: false, defaultInstantRetries: true);
        $anna->deleteProjection(self::NAME)->initializeProjection(self::NAME);
        $annaProjection->arm(fn () => $ben->sendCommand(new RegisterTicket('ticket-ben', 'Ben', 'alert')));

        $anna->sendCommand(new RegisterTicket('ticket-anna', 'Anna', 'alert'));

        self::assertSame(['ticket-anna', 'ticket-ben'], $anna->sendQueryWithRouting('optimistic.projectedTickets'));
    }

    public function test_an_asynchronous_projection_run_that_another_run_overtook_fails_with_a_concurrency_exception_and_projects_nothing(): void
    {
        [$anna, $annaProjection] = $this->bootstrap(self::getConnectionFactory(), asynchronous: true);
        [$ben] = $this->bootstrap($this->secondConnectionFactory(), asynchronous: true);
        $anna->deleteProjection(self::NAME)->initializeProjection(self::NAME);
        $anna->sendCommand(new RegisterTicket('ticket-anna', 'Anna', 'alert'));
        $annaProjection->arm(function () use ($ben) {
            $ben->sendCommand(new RegisterTicket('ticket-ben', 'Ben', 'alert'));
            $ben->run(self::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        });

        try {
            $anna->run(self::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
            self::fail('Expected a ConcurrencyException');
        } catch (ConcurrencyException $exception) {
            self::assertStringContainsString("Projection 'optimistic_tickets_projection' (partition: global) was loaded at version", $exception->getMessage());
        }

        self::assertSame(['ticket-anna', 'ticket-ben'], $anna->sendQueryWithRouting('optimistic.projectedTickets'));
    }

    public function test_an_asynchronous_projection_run_that_another_run_overtook_is_retried_by_default_and_projects_every_event_once(): void
    {
        [$anna, $annaProjection] = $this->bootstrap(self::getConnectionFactory(), asynchronous: true, defaultInstantRetries: true);
        [$ben] = $this->bootstrap($this->secondConnectionFactory(), asynchronous: true, defaultInstantRetries: true);
        $anna->deleteProjection(self::NAME)->initializeProjection(self::NAME);
        $anna->sendCommand(new RegisterTicket('ticket-anna', 'Anna', 'alert'));
        $annaProjection->arm(function () use ($ben) {
            $ben->sendCommand(new RegisterTicket('ticket-ben', 'Ben', 'alert'));
            $ben->run(self::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        });

        $anna->run(self::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));

        self::assertSame(['ticket-anna', 'ticket-ben'], $anna->sendQueryWithRouting('optimistic.projectedTickets'));
    }

    /**
     * @return array{0: FlowTestSupport, 1: object}
     */
    private function bootstrap(DbalConnectionFactory $connectionFactory, bool $asynchronous, bool $defaultInstantRetries = false): array
    {
        $connection = $connectionFactory->createContext()->getDbalConnection();
        $projection = $asynchronous
            ? new #[Projection(ProjectionOptimisticLockingTest::NAME), Asynchronous(ProjectionOptimisticLockingTest::CHANNEL), FromAggregateStream(Ticket::class)] class ($connection) {
                private ?Closure $competingRun = null;

                public function __construct(private Connection $connection)
                {
                }

                public function arm(Closure $competingRun): void
                {
                    $this->competingRun = $competingRun;
                }

                #[QueryHandler('optimistic.projectedTickets')]
                public function projectedTickets(): array
                {
                    return $this->connection->executeQuery('SELECT ticket_id FROM optimistic_projected_tickets ORDER BY ticket_id')->fetchFirstColumn();
                }

                #[EventHandler]
                public function whenRegistered(TicketWasRegistered $event): void
                {
                    $competingRun = $this->competingRun;
                    $this->competingRun = null;
                    if ($competingRun !== null) {
                        $competingRun();
                    }

                    $this->connection->executeStatement('INSERT INTO optimistic_projected_tickets VALUES (?)', [$event->getTicketId()]);
                }

                #[ProjectionInitialization]
                public function initialization(): void
                {
                }

                #[ProjectionDelete]
                public function delete(): void
                {
                    $this->connection->executeStatement('DELETE FROM optimistic_projected_tickets');
                }
            }
        : new #[Projection(ProjectionOptimisticLockingTest::NAME), FromAggregateStream(Ticket::class)] class ($connection) {
            private ?Closure $competingRun = null;

            public function __construct(private Connection $connection)
            {
            }

            public function arm(Closure $competingRun): void
            {
                $this->competingRun = $competingRun;
            }

            #[QueryHandler('optimistic.projectedTickets')]
            public function projectedTickets(): array
            {
                return $this->connection->executeQuery('SELECT ticket_id FROM optimistic_projected_tickets ORDER BY ticket_id')->fetchFirstColumn();
            }

            #[EventHandler]
            public function whenRegistered(TicketWasRegistered $event): void
            {
                $competingRun = $this->competingRun;
                $this->competingRun = null;
                if ($competingRun !== null) {
                    $competingRun();
                }

                $this->connection->executeStatement('INSERT INTO optimistic_projected_tickets VALUES (?)', [$event->getTicketId()]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DELETE FROM optimistic_projected_tickets');
            }
        };

        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [$projection::class, Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [$projection, new TicketEventConverter(), DbalConnectionFactory::class => $connectionFactory],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel(self::CHANNEL)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries($defaultInstantRetries),
        );

        return [$ecotone, $projection];
    }

    private function secondConnectionFactory(): DbalConnectionFactory
    {
        $connectionFactory = new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone');
        $connection = $connectionFactory->createContext()->getDbalConnection();
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $connection->executeStatement('SET lock_timeout = 2000');
        } else {
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 2');
        }

        return $connectionFactory;
    }
}
