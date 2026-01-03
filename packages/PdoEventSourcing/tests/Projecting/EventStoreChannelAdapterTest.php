<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\InternalHandler;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Consumer\InMemory\InMemoryConsumerPositionTracker;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\EventStoreAdapter\EventStreamingChannelAdapter;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * Tests for EventStoreChannelAdapter - feeding events from event store to streaming channels
 * @internal
 */
final class EventStoreChannelAdapterTest extends ProjectingTestCase
{
    public function test_feeding_events_from_event_store_to_streaming_channel(): void
    {
        $positionTracker = new InMemoryConsumerPositionTracker();

        // Consumer that reads from the streaming channel
        $consumer = new class () {
            public array $consumedEvents = [];

            #[InternalHandler(inputChannelName: 'event_stream', endpointId: 'stream_consumer')]
            public function handle(TicketWasRegistered $event): void
            {
                $this->consumedEvents[] = $event;
            }

            #[QueryHandler('getConsumed')]
            public function getConsumed(): array
            {
                return $this->consumedEvents;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Ticket::class, TicketEventConverter::class, $consumer::class],
            containerOrAvailableServices: [
                new TicketEventConverter(),
                $consumer,
                self::getConnectionFactory(),
                ConsumerPositionTracker::class => $positionTracker,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createStreamingChannel('event_stream'),
                    EventStreamingChannelAdapter::create(
                        streamChannelName: 'event_stream',
                        endpointId: 'event_store_feeder',
                        fromStream: Ticket::class
                    ),
                    PollingMetadata::create('stream_consumer')->withTestingSetup(),
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // When events are stored in event store
        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'John', 'bug'));
        $ecotone->sendCommand(new RegisterTicket('ticket-2', 'Jane', 'feature'));

        // Then consumer hasn't received anything yet
        $this->assertCount(0, $ecotone->sendQueryWithRouting('getConsumed'));

        // When feeder runs (polls event store and pushes to streaming channel)
        $ecotone->run('event_store_feeder', ExecutionPollingMetadata::createWithTestingSetup());

        // Then events are in the streaming channel but not consumed yet
        $this->assertCount(0, $ecotone->sendQueryWithRouting('getConsumed'));

        // When stream consumer runs (handle 2 messages)
        $ecotone->run('stream_consumer', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2));

        // Then events are consumed
        $consumedEvents = $ecotone->sendQueryWithRouting('getConsumed');
        $this->assertCount(2, $consumedEvents);
        $this->assertEquals('ticket-1', $consumedEvents[0]->getTicketId());
        $this->assertEquals('ticket-2', $consumedEvents[1]->getTicketId());
    }

    public function test_filtering_events_by_name_using_glob_patterns(): void
    {
        $positionTracker = new InMemoryConsumerPositionTracker();
        $consumer = new class () {
            private array $consumed = [];

            #[InternalHandler(endpointId: 'stream_consumer', inputChannelName: 'event_stream')]
            public function handle(TicketWasRegistered $event): void
            {
                $this->consumed[] = $event;
            }

            #[QueryHandler('getConsumed')]
            public function getConsumed(): array
            {
                return $this->consumed;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Ticket::class, TicketEventConverter::class, $consumer::class],
            containerOrAvailableServices: [
                new TicketEventConverter(),
                $consumer,
                self::getConnectionFactory(),
                ConsumerPositionTracker::class => $positionTracker,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createStreamingChannel('event_stream'),
                    EventStreamingChannelAdapter::create(
                        streamChannelName: 'event_stream',
                        endpointId: 'event_store_feeder',
                        fromStream: Ticket::class
                    )
                        ->withEventNames(['*TicketWasRegistered']), // Only TicketWasRegistered events
                    PollingMetadata::create('stream_consumer')->withTestingSetup(),
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // When events are stored in event store (2 TicketWasRegistered + 2 TicketWasClosed)
        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'John', 'bug'));
        $ecotone->sendCommand(new CloseTicket('ticket-1'));
        $ecotone->sendCommand(new RegisterTicket('ticket-2', 'Jane', 'feature'));
        $ecotone->sendCommand(new CloseTicket('ticket-2'));

        // When feeder runs (polls event store and pushes filtered events to streaming channel)
        $ecotone->run('event_store_feeder', ExecutionPollingMetadata::createWithTestingSetup());

        // When stream consumer runs (handle 2 messages - only TicketWasRegistered events)
        $ecotone->run('stream_consumer', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2));

        // Then only TicketWasRegistered events are consumed (TicketWasClosed events are filtered out)
        $consumedEvents = $ecotone->sendQueryWithRouting('getConsumed');
        $this->assertCount(2, $consumedEvents);
        $this->assertEquals('ticket-1', $consumedEvents[0]->getTicketId());
        $this->assertEquals('ticket-2', $consumedEvents[1]->getTicketId());
    }

    public function test_normal_projection_works_alongside_event_store_channel_adapter(): void
    {
        $positionTracker = new InMemoryConsumerPositionTracker();

        // Normal event handler that counts tickets (not a projection, just a simple event handler)
        // This demonstrates that EventStoreChannelAdapter works alongside normal event handlers
        $ticketCounter = new class () {
            public int $registeredCount = 0;
            public int $closedCount = 0;

            #[EventHandler(endpointId: 'ticket_counter.registered')]
            public function whenRegistered(TicketWasRegistered $event): void
            {
                $this->registeredCount++;
            }

            #[EventHandler(endpointId: 'ticket_counter.closed')]
            public function whenClosed(TicketWasClosed $event): void
            {
                $this->closedCount++;
            }

            #[QueryHandler('getTicketCounts')]
            public function getCounts(): array
            {
                return ['registered' => $this->registeredCount, 'closed' => $this->closedCount];
            }
        };

        // Consumer that reads from the streaming channel
        $consumer = new class () {
            private array $consumed = [];

            #[InternalHandler(inputChannelName: 'event_stream', endpointId: 'stream_consumer')]
            public function handle(array $event): void
            {
                $this->consumed[] = $event;
            }

            #[QueryHandler('getConsumed')]
            public function getConsumed(): array
            {
                return $this->consumed;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Ticket::class, TicketEventConverter::class, $ticketCounter::class, $consumer::class],
            containerOrAvailableServices: [
                new TicketEventConverter(),
                $ticketCounter,
                $consumer,
                self::getConnectionFactory(),
                ConsumerPositionTracker::class => $positionTracker,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    SimpleMessageChannelBuilder::createStreamingChannel('event_stream'),
                    EventStreamingChannelAdapter::create(
                        streamChannelName: 'event_stream',
                        endpointId: 'event_store_feeder',
                        fromStream: Ticket::class
                    ),
                    PollingMetadata::create('stream_consumer')->withTestingSetup(),
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // When events are stored in event store
        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'John', 'bug'));
        $ecotone->sendCommand(new RegisterTicket('ticket-2', 'Jane', 'feature'));
        $ecotone->sendCommand(new CloseTicket('ticket-1'));

        // Then normal event handler processes all events synchronously (event-driven by default)
        $counts = $ecotone->sendQueryWithRouting('getTicketCounts');
        $this->assertEquals(2, $counts['registered'], 'Event handler should have processed 2 TicketWasRegistered events');
        $this->assertEquals(1, $counts['closed'], 'Event handler should have processed 1 TicketWasClosed event');

        // When feeder runs (polls event store and pushes to streaming channel)
        $ecotone->run('event_store_feeder', ExecutionPollingMetadata::createWithTestingSetup());

        // When stream consumer runs (handle 3 messages)
        $ecotone->run('stream_consumer', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 3));

        // Then events are also consumed from streaming channel (as arrays)
        $consumedEvents = $ecotone->sendQueryWithRouting('getConsumed');
        $this->assertCount(3, $consumedEvents, 'Should have consumed 3 events from streaming channel');
        $this->assertEquals('ticket-1', $consumedEvents[0]['ticketId']);
        $this->assertEquals('ticket-2', $consumedEvents[1]['ticketId']);
        $this->assertEquals('ticket-1', $consumedEvents[2]['ticketId']); // CloseTicket event
    }
}
