<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * licence Apache-2.0
 * @internal
 */
final class InstantRetryByDefaultProjectionTest extends ProjectingTestCase
{
    public function test_an_asynchronous_projection_is_retried_by_default_when_asynchronous_endpoints_run_without_a_database_transaction(): void
    {
        $this->assertProjectedOnceAfterFailingTwiceInOneRun(licenceKey: null);
    }

    public function test_an_enterprise_asynchronous_projection_managing_its_own_transaction_is_retried_by_default(): void
    {
        $this->assertProjectedOnceAfterFailingTwiceInOneRun(licenceKey: LicenceTesting::VALID_LICENCE);
    }

    private function assertProjectedOnceAfterFailingTwiceInOneRun(?string $licenceKey): void
    {
        $projection = $this->flakyProjection();
        $ecotone = $this->bootstrap($projection, $licenceKey);
        $this->getConnection()->executeStatement('CREATE TABLE IF NOT EXISTS retried_by_default_tickets (ticket_id VARCHAR(36) PRIMARY KEY)');
        $ecotone->deleteProjection($projection::NAME)->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'User1', 'alert'));
        $ecotone->run($projection::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        self::assertSame(3, $projection->attempts);
        self::assertSame([['ticket_id' => 'ticket-1']], $ecotone->sendQueryWithRouting('retriedByDefault.tickets'));
    }

    private function flakyProjection(): object
    {
        return new #[Projection(self::NAME), Asynchronous(self::CHANNEL), FromAggregateStream(Ticket::class)] class ($this->getConnection()) {
            public const NAME = 'retried_by_default_projection';
            public const CHANNEL = 'retried_by_default_channel';
            public int $attempts = 0;

            public function __construct(private Connection $connection)
            {
            }

            #[QueryHandler('retriedByDefault.tickets')]
            public function tickets(): array
            {
                return $this->connection->executeQuery('SELECT ticket_id FROM retried_by_default_tickets')->fetchAllAssociative();
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                $this->attempts++;
                $this->connection->executeStatement('INSERT INTO retried_by_default_tickets VALUES (?)', [$event->getTicketId()]);
                if ($this->attempts < 3) {
                    throw new RuntimeException('flaky projection');
                }
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DELETE FROM retried_by_default_tickets');
            }
        };
    }

    private function bootstrap(object $projection, ?string $licenceKey): FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            classesToResolve: [$projection::class, Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [$projection, new TicketEventConverter(), self::getConnectionFactory()],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(false),
                DbalBackedMessageChannelBuilder::create($projection::CHANNEL),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(true),
            licenceKey: $licenceKey,
        );
    }
}
