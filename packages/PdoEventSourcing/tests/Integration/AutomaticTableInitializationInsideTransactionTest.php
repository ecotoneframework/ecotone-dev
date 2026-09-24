<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection\TicketListUpdated;
use Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection\TicketListUpdatedConverter;

/**
 * licence Apache-2.0
 * @internal
 */
final class AutomaticTableInitializationInsideTransactionTest extends EventSourcingMessagingTestCase
{
    private const NEVER_CREATED_STREAM = 'auto_initialized_inside_open_transaction_stream';

    public function test_emitting_to_a_stream_table_created_for_the_first_time_inside_an_open_transaction_commits_cleanly(): void
    {
        self::assertFalse(self::tableExists($this->getConnection(), self::NEVER_CREATED_STREAM));

        $projection = new #[Projection('auto_initialized_stream_projection'), FromAggregateStream(Ticket::class), Stream('auto_initialized_inside_open_transaction_stream')] class () {
            #[EventHandler(endpointId: 'autoInitializedStream.addTicket')]
            public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
            {
                $eventStreamEmitter->emit([new TicketListUpdated($event->getTicketId())]);
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Ticket::class, TicketEventConverter::class, TicketListUpdatedConverter::class, TicketListUpdated::class, $projection::class],
            containerOrAvailableServices: [
                $projection,
                new TicketEventConverter(),
                new TicketListUpdatedConverter(),
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        self::assertCount(1, $ecotone->getGateway(EventStore::class)->load(self::NEVER_CREATED_STREAM));
    }
}
