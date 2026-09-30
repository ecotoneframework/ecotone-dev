<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionRegistry;
use Ecotone\Api\Projecting\ProjectionReset;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * licence Apache-2.0
 * @internal
 */
final class EventStreamDeletionTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';

    private const EMITTED_STREAM = 'ticket_notifications';

    public function test_deleting_a_stream_outside_a_transaction_removes_it(): void
    {
        $eventStore = $this->bootstrapEcotone()->getGateway(EventStore::class);

        $eventStore->delete(self::STREAM);

        self::assertFalse($eventStore->hasStream(self::STREAM));
    }

    public function test_deleting_a_stream_from_a_handler_without_database_transaction_removes_it(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommandWithRouting('stream.deleteWithoutTransaction', self::STREAM);

        self::assertFalse($ecotone->getGateway(EventStore::class)->hasStream(self::STREAM));
    }

    public function test_deleting_a_stream_inside_the_message_transaction_removes_it_where_ddl_is_transactional(): void
    {
        if ($this->isDdlImplicitlyCommitting()) {
            $this->markTestSkipped('DDL implicitly commits the surrounding transaction on MySQL and MariaDB.');
        }
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommandWithRouting('stream.delete', self::STREAM);

        self::assertFalse($ecotone->getGateway(EventStore::class)->hasStream(self::STREAM));
    }

    public function test_deleting_a_stream_inside_the_message_transaction_is_refused_where_ddl_implicitly_commits(): void
    {
        if (! $this->isDdlImplicitlyCommitting()) {
            $this->markTestSkipped('DDL is transactional on PostgreSQL and SQLite.');
        }
        $ecotone = $this->bootstrapEcotone();

        try {
            $ecotone->sendCommandWithRouting('stream.delete', self::STREAM);
            self::fail('Expected a ConfigurationException');
        } catch (ConfigurationException $exception) {
            self::assertSame(self::refusalFor(self::STREAM), $exception->getMessage());
        }

        self::assertTrue($ecotone->getGateway(EventStore::class)->hasStream(self::STREAM));
    }

    public function test_deleting_a_stream_while_rebuilding_a_projection_removes_it_where_ddl_is_transactional(): void
    {
        if ($this->isDdlImplicitlyCommitting()) {
            $this->markTestSkipped('DDL implicitly commits the surrounding transaction on MySQL and MariaDB.');
        }
        $ecotone = $this->bootstrapEcotoneWithProjectionDeletingItsStreamOnReset();

        $ecotone->getGateway(ProjectionRegistry::class)->get('ticket_notifications')->prepareRebuild();

        self::assertFalse($ecotone->getGateway(EventStore::class)->hasStream(self::EMITTED_STREAM));
    }

    public function test_deleting_a_stream_while_rebuilding_a_projection_is_refused_where_ddl_implicitly_commits(): void
    {
        if (! $this->isDdlImplicitlyCommitting()) {
            $this->markTestSkipped('DDL is transactional on PostgreSQL and SQLite.');
        }
        $ecotone = $this->bootstrapEcotoneWithProjectionDeletingItsStreamOnReset();

        try {
            $ecotone->getGateway(ProjectionRegistry::class)->get('ticket_notifications')->prepareRebuild();
            self::fail('Expected a ConfigurationException');
        } catch (ConfigurationException $exception) {
            self::assertSame(self::refusalFor(self::EMITTED_STREAM), $exception->getMessage());
        }

        self::assertTrue($ecotone->getGateway(EventStore::class)->hasStream(self::EMITTED_STREAM));
    }

    private static function refusalFor(string $streamName): string
    {
        return "Event stream '{$streamName}' cannot be deleted inside a database transaction on MySQL/MariaDB: "
            . "deleting a stream drops its table '{$streamName}', and DDL implicitly commits the surrounding transaction there, "
            . 'so everything written before it would be committed even if the message fails afterwards. '
            . 'Delete the stream outside any database transaction instead: '
            . 'mark the command handler, asynchronous handler or #[ConsoleCommand] that deletes it with #[WithoutDatabaseTransaction] '
            . '(each runs inside a transaction by default), '
            . 'turn the transaction off for that entry point with DbalConfiguration::withTransactionOnCommandBus(false), '
            . 'withTransactionOnAsynchronousEndpoints(false) or withTransactionOnConsoleCommands(false), '
            . 'commit your own transaction before calling EventStore::delete(), '
            . 'or move the deletion out of #[ProjectionReset], which always runs inside the projection\'s transaction, '
            . 'into #[ProjectionDelete] and delete the projection before rebuilding it. '
            . 'Otherwise run the event store on PostgreSQL or SQLite, where DDL is transactional.';
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $streamDeletion = new class () {
            #[CommandHandler('stream.delete')]
            public function delete(string $streamName, #[Reference] EventStore $eventStore): void
            {
                $eventStore->delete($streamName);
            }

            #[WithoutDatabaseTransaction]
            #[CommandHandler('stream.deleteWithoutTransaction')]
            public function deleteWithoutTransaction(string $streamName, #[Reference] EventStore $eventStore): void
            {
                $eventStore->delete($streamName);
            }
        };

        return $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [$streamDeletion::class],
            containerOrAvailableServices: [$streamDeletion, DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
        );
    }

    private function bootstrapEcotoneWithProjectionDeletingItsStreamOnReset(): FlowTestSupport
    {
        $projection = new #[Projection('ticket_notifications'), FromAggregateStream(Ticket::class), Stream('ticket_notifications')] class () {
            #[EventHandler]
            public function whenRegistered(TicketWasRegistered $event): void
            {
            }

            #[ProjectionReset]
            public function reset(#[Reference] EventStore $eventStore): void
            {
                $eventStore->delete('ticket_notifications');
            }
        };

        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [$projection::class, Ticket::class],
            containerOrAvailableServices: [$projection, new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces(['Test\Ecotone\EventSourcing\Fixture\Ticket'])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
        );

        return $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
    }

    private function isDdlImplicitlyCommitting(): bool
    {
        return self::getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }
}
