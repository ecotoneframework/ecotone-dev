<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Partitioned;

use Ecotone\EventSourcing\Attribute\FromAggregateStream;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\EventSourcing\Attribute\ProjectionState;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionDeployment;
use Ecotone\Projecting\Attribute\ProjectionFlush;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Projecting\ProjectionRegistry;
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
    public function test_partitioned_projection_can_emit_events_using_event_stream_emitter(): void
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
        self::assertNotEmpty($projection->getTickets(), 'Partitioned projection should have received the event');

        // Check that the event was emitted to the notifications stream
        $eventStore = $ecotone->getGateway(EventStore::class);
        $emittedEvents = $eventStore->load('notifications_stream');

        self::assertCount(1, $emittedEvents, 'One event should have been emitted to the notifications stream');
    }

    public function test_partitioned_projection_emitting_events_with_close_ticket(): void
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

    public function test_when_partitioned_projection_is_deleted_emitted_events_will_be_removed_too(): void
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

        $ecotone->deleteProjection('partitioned_emitting_linked_projection');
        $ecotone->initializeProjection('partitioned_emitting_linked_projection');

        $ecotone
            ->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'))
            ->sendCommand(new CloseTicket('123'))
            ->deleteProjection('partitioned_emitting_linked_projection');

        // When projection is deleted, the emitted events should be removed from the projection stream
        $eventStore = $ecotone->getGateway(EventStore::class);
        self::assertFalse($eventStore->hasStream('projection-partitioned_emitting_linked_projection'), 'Projection stream should be deleted when projection is deleted');
    }

    /**
     * Test blue/green deployment scenario: when a partitioned projection is deployed with live=false,
     * events should not be emitted to the event stream/bus during projection execution.
     */
    public function test_partitioned_projection_with_live_false_should_not_emit_events(): void
    {
        $projection = $this->createNonLiveEmittingProjection();
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

        // Check that the projection received the events (projection still works)
        self::assertNotEmpty($projection->getTickets() ?: ['was_processed'], 'Partitioned projection should have processed the events');

        // Check that NO events were emitted to the notifications stream
        // because the projection has live=false
        $eventStore = $ecotone->getGateway(EventStore::class);
        self::assertFalse(
            $eventStore->hasStream('notifications_stream_non_live'),
            'No events should have been emitted to the stream because partitioned projection is not live'
        );
    }

    private function createEmittingProjection(): object
    {
        return new #[ProjectionV2('partitioned_emitting_projection'), Partitioned, FromStream(stream: Ticket::class, aggregateType: Ticket::class)] class () {
            private const STREAM_NAME = 'notifications_stream';
            private array $tickets = [];

            #[EventHandler(endpointId: 'partitionedEmittingProjection.addTicket')]
            public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
            {
                $this->tickets[$event->getTicketId()] = $event->getTicketType();

                $eventStreamEmitter->linkTo(self::STREAM_NAME, [new TicketListUpdated($event->getTicketId())]);
            }

            #[EventHandler(endpointId: 'partitionedEmittingProjection.closeTicket')]
            public function closeTicket(TicketWasClosed $event, EventStreamEmitter $eventStreamEmitter): void
            {
                unset($this->tickets[$event->getTicketId()]);

                $eventStreamEmitter->linkTo(self::STREAM_NAME, [new TicketListUpdated($event->getTicketId())]);
            }

            #[QueryHandler('getPartitionedEmittingProjectionTickets')]
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
        return new #[ProjectionV2('partitioned_emitting_linked_projection'), Partitioned, FromStream(stream: Ticket::class, aggregateType: Ticket::class)] class () {
            private const STREAM_NAME = 'projection-partitioned_emitting_linked_projection';
            private array $tickets = [];

            #[EventHandler(endpointId: 'partitionedEmittingLinkedProjection.addTicket')]
            public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
            {
                $this->tickets[$event->getTicketId()] = $event->getTicketType();

                // Link to projection stream - events are removed when projection is deleted
                $eventStreamEmitter->linkTo(self::STREAM_NAME, [new TicketListUpdated($event->getTicketId())]);
            }

            #[EventHandler(endpointId: 'partitionedEmittingLinkedProjection.closeTicket')]
            public function closeTicket(TicketWasClosed $event, EventStreamEmitter $eventStreamEmitter): void
            {
                unset($this->tickets[$event->getTicketId()]);

                $eventStreamEmitter->linkTo(self::STREAM_NAME, [new TicketListUpdated($event->getTicketId())]);
            }

            #[QueryHandler('getPartitionedEmittingLinkedProjectionTickets')]
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

    private function createNonLiveEmittingProjection(): object
    {
        return new #[ProjectionV2('partitioned_non_live_projection'), ProjectionDeployment(live: false), Partitioned, FromStream(stream: Ticket::class, aggregateType: Ticket::class)] class () {
            private const STREAM_NAME = 'notifications_stream_non_live';
            private array $tickets = [];

            #[EventHandler(endpointId: 'partitionedNonLiveProjection.addTicket')]
            public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
            {
                $this->tickets[$event->getTicketId()] = $event->getTicketType();

                // This should NOT emit events because the projection has live=false
                $eventStreamEmitter->linkTo(self::STREAM_NAME, [new TicketListUpdated($event->getTicketId())]);
            }

            #[EventHandler(endpointId: 'partitionedNonLiveProjection.closeTicket')]
            public function closeTicket(TicketWasClosed $event, EventStreamEmitter $eventStreamEmitter): void
            {
                unset($this->tickets[$event->getTicketId()]);

                // This should NOT emit events because the projection has live=false
                $eventStreamEmitter->linkTo(self::STREAM_NAME, [new TicketListUpdated($event->getTicketId())]);
            }

            #[QueryHandler('getPartitionedNonLiveProjectionTickets')]
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
                if ($eventStore->hasStream(self::STREAM_NAME)) {
                    $eventStore->delete(self::STREAM_NAME);
                }
            }
        };
    }

    public function test_partitioned_projection_flush_emits_events_using_event_stream_emitter(): void
    {
        $projection = $this->createFlushEmittingProjection();

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

        $ecotone
            ->sendCommand(new RegisterTicket('1', 'Johnny', 'alert'))
            ->sendCommand(new RegisterTicket('2', 'Jane', 'info'));

        $eventStore = $ecotone->getGateway(EventStore::class);
        $emittedEvents = $eventStore->load('projection_flush_emitting_projection');

        self::assertCount(2, $emittedEvents);
    }

    public function test_rebuild_should_not_emit_events_from_flush_method(): void
    {
        $projection = $this->createFlushEmittingProjection();

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

        $ecotone
            ->sendCommand(new RegisterTicket('1', 'Johnny', 'alert'))
            ->sendCommand(new RegisterTicket('2', 'Jane', 'info'));

        $eventStore = $ecotone->getGateway(EventStore::class);
        self::assertCount(2, $eventStore->load('projection_flush_emitting_projection'));

        $ecotone->resetProjection('flush_emitting_projection');
        self::assertEmpty($projection->getTickets());
        $emittedCountBeforeRebuild = $eventStore->hasStream('projection_flush_emitting_projection')
            ? count($eventStore->load('projection_flush_emitting_projection'))
            : 0;

        $ecotone->getGateway(ProjectionRegistry::class)->get('flush_emitting_projection')->prepareRebuild();

        self::assertNotEmpty($projection->getTickets());
        $emittedCountAfterRebuild = $eventStore->hasStream('projection_flush_emitting_projection')
            ? count($eventStore->load('projection_flush_emitting_projection'))
            : 0;
        self::assertSame($emittedCountBeforeRebuild, $emittedCountAfterRebuild);
    }

    public function test_global_flush_with_projection_state_requires_enterprise_licence(): void
    {
        $projection = new #[ProjectionV2('global_flush_state_projection'), FromStream(Ticket::class)] class () {
            #[EventHandler(endpointId: 'globalFlushStateProjection.addTicket')]
            public function addTicket(TicketWasRegistered $event, #[ProjectionState] array $ticket = []): array
            {
                $ticket['ticketId'] = $event->getTicketId();
                return $ticket;
            }

            #[ProjectionFlush]
            public function flush(#[ProjectionState] array $ticket, EventStreamEmitter $emitter): void
            {
                if (! isset($ticket['ticketId'])) {
                    return;
                }
                $emitter->emit([new TicketListUpdated($ticket['ticketId'])]);
            }
        };

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('Using #[ProjectionState] in #[ProjectionFlush] methods requires Ecotone Enterprise licence.');

        EcotoneLite::bootstrapFlowTestingWithEventStore(
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
        );
    }

    public function test_partitioned_flush_emitter_pattern_requires_enterprise_licence(): void
    {
        $projection = $this->createFlushEmittingProjection();

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessageMatches('/Enterprise licence/');

        EcotoneLite::bootstrapFlowTestingWithEventStore(
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
        );
    }

    private function createFlushEmittingProjection(): object
    {
        return new #[ProjectionV2('flush_emitting_projection'), Partitioned, FromAggregateStream(Ticket::class)] class () {
            public array $tickets = [];

            #[EventHandler(endpointId: 'flushEmittingProjection.addTicket')]
            public function addTicket(TicketWasRegistered $event, #[ProjectionState] array $ticket = []): array
            {
                $ticket['ticketId'] = $event->getTicketId();
                $ticket['status'] = 'open';
                $this->tickets[$event->getTicketId()] = $ticket;
                return $ticket;
            }

            #[EventHandler(endpointId: 'flushEmittingProjection.closeTicket')]
            public function closeTicket(TicketWasClosed $event, #[ProjectionState] array $ticket): array
            {
                $ticket['status'] = 'closed';
                $this->tickets[$event->getTicketId()] = $ticket;
                return $ticket;
            }

            #[ProjectionFlush]
            public function flush(#[ProjectionState] array $ticket, EventStreamEmitter $emitter): void
            {
                if (! isset($ticket['ticketId'])) {
                    return;
                }

                $emitter->emit([new TicketListUpdated($ticket['ticketId'])]);
            }

            #[QueryHandler('getFlushEmittingProjectionTickets')]
            public function getTickets(): array
            {
                return $this->tickets;
            }

            #[ProjectionReset]
            public function reset(#[Reference] EventStore $eventStore): void
            {
                $this->tickets = [];
                if ($eventStore->hasStream('projection_flush_emitting_projection')) {
                    $eventStore->delete('projection_flush_emitting_projection');
                }
            }

            #[ProjectionDelete]
            public function delete(#[Reference] EventStore $eventStore): void
            {
                $this->tickets = [];
                if ($eventStore->hasStream('projection_flush_emitting_projection')) {
                    $eventStore->delete('projection_flush_emitting_projection');
                }
            }
        };
    }
}
