<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\GlobalProjection;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection\NotificationService;
use Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection\TicketListUpdated;
use Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection\TicketListUpdatedConverter;

/**
 * Tests for emitting events from GlobalProjection handlers.
 *
 * EventStreamEmitter is a general EventSourcing feature that can be used with any event handler,
 * including GlobalProjection handlers. It allows projections to emit events to other streams.
 *
 * @internal
 */
final class EmittingEventsProjectionTest extends EventSourcingMessagingTestCase
{
    public function test_projection_can_emit_events_using_event_stream_emitter(): void
    {
        $projection = $this->createEmittingProjection();
        $notificationService = new NotificationService();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [\get_class($projection), NotificationService::class, TicketListUpdatedConverter::class, TicketListUpdated::class],
            containerOrAvailableServices: [
                $projection,
                $notificationService,
                new TicketEventConverter(),
                new TicketListUpdatedConverter(),
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        // Check that the projection received the event
        self::assertNotEmpty($projection->getTickets(), 'Projection should have received the event');

        // Check that the event was emitted to the notifications stream
        $eventStore = $ecotone->getGateway(EventStore::class);
        $emittedEvents = $eventStore->load('notifications_stream');

        self::assertCount(1, $emittedEvents, 'One event should have been emitted to the notifications stream');
    }

    public function test_projection_emitting_events_with_close_ticket(): void
    {
        $projection = $this->createEmittingProjection();
        $notificationService = new NotificationService();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [\get_class($projection), NotificationService::class, TicketListUpdatedConverter::class, TicketListUpdated::class],
            containerOrAvailableServices: [
                $projection,
                $notificationService,
                new TicketEventConverter(),
                new TicketListUpdatedConverter(),
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone
            ->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'))
            ->sendCommand(new CloseTicket('123'));

        // Check that both events were emitted
        $eventStore = $ecotone->getGateway(EventStore::class);
        $emittedEvents = $eventStore->load('notifications_stream');

        self::assertCount(2, $emittedEvents, 'Two events should have been emitted to the notifications stream');
    }

    private function createEmittingProjection(): object
    {
        $connection = $this->getConnection();

        return new #[GlobalProjection('emitting_projection'), FromStream(Ticket::class)] class($connection) {
            private array $tickets = [];

            public function __construct(private Connection $connection)
            {
            }

            #[EventHandler(endpointId: 'emittingProjection.addTicket')]
            public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
            {
                $this->tickets[$event->getTicketId()] = $event->getTicketType();

                $eventStreamEmitter->linkTo('notifications_stream', [new TicketListUpdated($event->getTicketId())]);
            }

            #[EventHandler(endpointId: 'emittingProjection.closeTicket')]
            public function closeTicket(TicketWasClosed $event, EventStreamEmitter $eventStreamEmitter): void
            {
                unset($this->tickets[$event->getTicketId()]);

                $eventStreamEmitter->linkTo('notifications_stream', [new TicketListUpdated($event->getTicketId())]);
            }

            #[QueryHandler('getEmittingProjectionTickets')]
            public function getTickets(): array
            {
                return $this->tickets;
            }

            #[ProjectionInitialization]
            public function init(): void
            {
                // No table needed - using in-memory storage
            }

            #[ProjectionReset]
            public function reset(): void
            {
                $this->tickets = [];
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->tickets = [];
            }
        };
    }
}

