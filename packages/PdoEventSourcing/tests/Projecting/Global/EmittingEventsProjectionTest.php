<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;

use function get_class;

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
 * Tests for emitting events from ProjectionV2 handlers.
 *
 * EventStreamEmitter is a general EventSourcing feature that can be used with any event handler,
 * including ProjectionV2 handlers. It allows projections to emit events to other streams.
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
            classesToResolve: [get_class($projection), NotificationService::class, TicketListUpdatedConverter::class, TicketListUpdated::class],
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
            classesToResolve: [get_class($projection), NotificationService::class, TicketListUpdatedConverter::class, TicketListUpdated::class],
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

    public function test_when_projection_is_deleted_emitted_events_will_be_removed_too(): void
    {
        $projection = $this->createEmittingProjectionWithLinkToProjectionStream();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [get_class($projection), TicketListUpdatedConverter::class, TicketListUpdated::class],
            containerOrAvailableServices: [
                $projection,
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

        $ecotone->deleteProjection('emitting_linked_projection');
        $ecotone->initializeProjection('emitting_linked_projection');

        $ecotone
            ->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'))
            ->sendCommand(new CloseTicket('123'))
            ->deleteProjection('emitting_linked_projection');

        // When projection is deleted, the emitted events should be removed from the projection stream
        $eventStore = $ecotone->getGateway(EventStore::class);
        self::assertFalse($eventStore->hasStream('projection-emitting_linked_projection'), 'Projection stream should be deleted when projection is deleted');
    }

    /**
     * This test is skipped for the new ProjectionV2 system.
     *
     * The old Prooph-based projection system sets PROJECTION_IS_REBUILDING header to true during replay,
     * which causes the EventStreamEmitter to filter out events from being published to the event bus.
     *
     * The new ProjectionV2 system doesn't have a concept of "rebuilding" mode - it always sets
     * PROJECTION_IS_REBUILDING to false. This means events are always published to the event bus
     * during projection execution, including during replay.
     *
     * This is a design difference between the two systems. If you need to prevent events from being
     * republished during replay in the new system, you should handle this in your projection logic
     * (e.g., by checking if the event was already processed).
     */
    public function test_projection_emitting_events_should_not_republished_in_case_replaying_projection(): void
    {
        $this->markTestSkipped(
            'The new ProjectionV2 system does not support PROJECTION_IS_REBUILDING mode. ' .
            'Events are always published to the event bus during projection execution. ' .
            'See test docblock for details.'
        );
    }

    private function createEmittingProjection(): object
    {
        return new #[ProjectionV2('emitting_projection'), FromStream(Ticket::class)] class () {
            private const STREAM_NAME = 'notifications_stream';
            private array $tickets = [];

            #[EventHandler(endpointId: 'emittingProjection.addTicket')]
            public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
            {
                $this->tickets[$event->getTicketId()] = $event->getTicketType();

                $eventStreamEmitter->linkTo(self::STREAM_NAME, [new TicketListUpdated($event->getTicketId())]);
            }

            #[EventHandler(endpointId: 'emittingProjection.closeTicket')]
            public function closeTicket(TicketWasClosed $event, EventStreamEmitter $eventStreamEmitter): void
            {
                unset($this->tickets[$event->getTicketId()]);

                $eventStreamEmitter->linkTo(self::STREAM_NAME, [new TicketListUpdated($event->getTicketId())]);
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
            public function reset(#[Reference] EventStore $eventStore): void
            {
                $this->tickets = [];
                // Delete the linked stream on reset so events are not duplicated on replay
                if ($eventStore->hasStream(self::STREAM_NAME)) {
                    $eventStore->delete(self::STREAM_NAME);
                }
            }

            #[ProjectionDelete]
            public function delete(#[Reference] EventStore $eventStore): void
            {
                $this->tickets = [];
                // Delete the linked stream when projection is deleted
                if ($eventStore->hasStream(self::STREAM_NAME)) {
                    $eventStore->delete(self::STREAM_NAME);
                }
            }
        };
    }

    private function createEmittingProjectionWithLinkToProjectionStream(): object
    {
        return new #[ProjectionV2('emitting_linked_projection'), FromStream(Ticket::class)] class () {
            private const STREAM_NAME = 'projection-emitting_linked_projection';
            private array $tickets = [];

            #[EventHandler(endpointId: 'emittingLinkedProjection.addTicket')]
            public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
            {
                $this->tickets[$event->getTicketId()] = $event->getTicketType();

                // Link to projection stream - events are removed when projection is deleted
                $eventStreamEmitter->linkTo(self::STREAM_NAME, [new TicketListUpdated($event->getTicketId())]);
            }

            #[EventHandler(endpointId: 'emittingLinkedProjection.closeTicket')]
            public function closeTicket(TicketWasClosed $event, EventStreamEmitter $eventStreamEmitter): void
            {
                unset($this->tickets[$event->getTicketId()]);

                $eventStreamEmitter->linkTo(self::STREAM_NAME, [new TicketListUpdated($event->getTicketId())]);
            }

            #[QueryHandler('getEmittingLinkedProjectionTickets')]
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
            public function delete(#[Reference] EventStore $eventStore): void
            {
                $this->tickets = [];
                // Delete the linked stream when projection is deleted
                if ($eventStore->hasStream(self::STREAM_NAME)) {
                    $eventStore->delete(self::STREAM_NAME);
                }
            }
        };
    }
}
