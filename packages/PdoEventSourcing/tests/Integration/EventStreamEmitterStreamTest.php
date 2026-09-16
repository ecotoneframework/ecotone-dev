<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
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
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class EventStreamEmitterStreamTest extends EventSourcingMessagingTestCase
{
    public function test_emitting_without_stream_attribute_writes_to_default_table(): void
    {
        $projection = new #[Projection('emitting_to_default_stream'), FromAggregateStream(Ticket::class)] class () {
            #[EventHandler(endpointId: 'emittingToDefaultStream.addTicket')]
            public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
            {
                $eventStreamEmitter->emit([new TicketListUpdated($event->getTicketId())]);
            }
        };

        $ecotone = $this->bootstrap($projection);
        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        $eventStore = $ecotone->getGateway(EventStore::class);
        $events = $eventStore->load(StreamTableRegistry::DEFAULT_STREAM);

        self::assertSame(
            [TicketWasRegistered::class, TicketListUpdated::class],
            array_map(fn ($event) => $event->getEventName(), $events)
        );
        self::assertFalse(self::tableExists($this->getConnection(), 'emitted_notifications'));
    }

    public function test_emitting_with_stream_attribute_writes_to_its_table(): void
    {
        $projection = new #[Projection('emitting_to_custom_stream'), FromAggregateStream(Ticket::class), Stream('emitted_notifications')] class () {
            #[EventHandler(endpointId: 'emittingToCustomStream.addTicket')]
            public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
            {
                $eventStreamEmitter->emit([new TicketListUpdated($event->getTicketId())]);
            }
        };

        $ecotone = $this->bootstrap($projection);
        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        $eventStore = $ecotone->getGateway(EventStore::class);

        self::assertCount(1, $eventStore->load(StreamTableRegistry::DEFAULT_STREAM));
        self::assertEquals(
            [new TicketListUpdated('123')],
            array_map(fn ($event) => $event->getPayload(), $eventStore->load('emitted_notifications'))
        );
    }

    public function test_linking_to_declared_stream(): void
    {
        $projection = new #[Projection('linking_to_declared_stream'), FromAggregateStream(Ticket::class), Stream('emitted_notifications')] class () {
            #[EventHandler(endpointId: 'linkingToDeclaredStream.addTicket')]
            public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
            {
                $eventStreamEmitter->linkTo('emitted_notifications', [new TicketListUpdated($event->getTicketId())]);
            }
        };

        $ecotone = $this->bootstrap($projection);
        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        self::assertCount(1, $ecotone->getGateway(EventStore::class)->load('emitted_notifications'));
    }

    public function test_linking_to_undeclared_stream_is_rejected(): void
    {
        $projection = new #[Projection('linking_to_undeclared_stream'), FromAggregateStream(Ticket::class)] class () {
            #[EventHandler(endpointId: 'linkingToUndeclaredStream.addTicket')]
            public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
            {
                $eventStreamEmitter->linkTo('never_declared_stream', [new TicketListUpdated($event->getTicketId())]);
            }
        };

        $ecotone = $this->bootstrap($projection);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/never_declared_stream/');

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
    }

    public function test_projection_reads_from_emitted_stream(): void
    {
        $emitting = new #[Projection('emitting_for_downstream'), FromAggregateStream(Ticket::class), Stream('emitted_notifications')] class () {
            #[EventHandler(endpointId: 'emittingForDownstream.addTicket')]
            public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
            {
                $eventStreamEmitter->emit([new TicketListUpdated($event->getTicketId())]);
            }
        };
        $downstream = new #[Projection('downstream_of_emitted'), FromStream('emitted_notifications')] class () {
            private array $updated = [];

            #[EventHandler(endpointId: 'downstreamOfEmitted.onUpdate')]
            public function onUpdate(TicketListUpdated $event): void
            {
                $this->updated[] = $event->ticketId;
            }

            #[QueryHandler('getUpdatedTickets')]
            public function getUpdated(): array
            {
                return $this->updated;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Ticket::class, TicketEventConverter::class, TicketListUpdatedConverter::class, TicketListUpdated::class, $emitting::class, $downstream::class],
            containerOrAvailableServices: [
                $emitting,
                $downstream,
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

        self::assertSame(['123'], $ecotone->sendQueryWithRouting('getUpdatedTickets'));
    }

    private function bootstrap(object $projection): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
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
    }
}
