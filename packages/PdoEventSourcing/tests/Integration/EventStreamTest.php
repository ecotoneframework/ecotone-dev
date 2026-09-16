<?php

declare(strict_types=1);

namespace Integration;

use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Database\EventStreamTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Modelling\Event;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class EventStreamTest extends EventSourcingMessagingTestCase
{
    public function test_storing_and_retrieving_events()
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getGateway(EventStore::class);

        $streamName = Uuid::v7()->toRfc4122();
        $eventStore->appendTo(
            $streamName,
            [
                Event::create(
                    $event = new TicketWasRegistered('123', 'Johnny', 'alert'),
                    $metadata = [
                        '_aggregate_id' => 1,
                        '_aggregate_version' => 1,
                        '_aggregate_type' => 'ticket',
                        'executor' => 'johnny',
                    ]
                ),
            ]
        );

        $events = $eventStore->load($streamName);

        $this->assertCount(1, $events);
        $this->assertEquals($event, $events[0]->getPayload());
        foreach ($metadata as $key => $value) {
            $this->assertEquals($value, $events[0]->getMetadata()[$key]);
        }
    }

    public function test_storing_events_without_aggregate_metadata()
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getGateway(EventStore::class);

        $streamName = Uuid::v7()->toRfc4122();
        $eventStore->create($streamName);
        $eventStore->appendTo(
            $streamName,
            [
                $eventOne = new TicketWasRegistered('123', 'Johnny', 'alert'),
            ]
        );
        $eventStore->appendTo(
            $streamName,
            [
                Event::create(
                    $eventTwo = new TicketWasClosed('123'),
                ),
            ]
        );

        $events = $eventStore->load($streamName);

        $this->assertCount(2, $events);
        $this->assertEquals($eventOne, $events[0]->getPayload());
        $this->assertEquals($eventTwo, $events[1]->getPayload());
    }

    public function test_storing_same_event_twice_without_aggregate_metadata()
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getGateway(EventStore::class);

        $streamName = Uuid::v7()->toRfc4122();
        $eventStore->create($streamName);
        $eventStore->appendTo($streamName, [new TicketWasRegistered('123', 'Johnny', 'alert')]);
        $eventStore->appendTo($streamName, [new TicketWasRegistered('123', 'Johnny', 'alert')]);

        $events = $eventStore->load($streamName);
        $this->assertCount(2, $events);
    }

    public function test_storing_same_aggregate_version_twice_is_rejected()
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getGateway(EventStore::class);

        $streamName = Uuid::v7()->toRfc4122();
        $eventStore->create($streamName);
        $eventStore->appendTo(
            $streamName,
            [
                Event::create(
                    new TicketWasRegistered('123', 'Johnny', 'alert'),
                    [
                        '_aggregate_id' => 1,
                        '_aggregate_version' => 1,
                        '_aggregate_type' => 'ticket',
                    ]
                ),
            ]
        );

        $this->expectException(ConcurrencyException::class);

        $eventStore->appendTo(
            $streamName,
            [
                Event::create(
                    new TicketWasRegistered('123', 'Johnny', 'alert'),
                    [
                        '_aggregate_id' => 1,
                        '_aggregate_version' => 1,
                        '_aggregate_type' => 'ticket',
                    ]
                ),
            ]
        );
    }

    public function test_storing_same_event_for_default_partioned_stream()
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getGateway(EventStore::class);

        $streamName = Uuid::v7()->toRfc4122();
        $eventStore->appendTo(
            $streamName,
            [
                Event::create(
                    new TicketWasRegistered('123', 'Johnny', 'alert'),
                    [
                        '_aggregate_id' => 1,
                        '_aggregate_version' => 1,
                        '_aggregate_type' => 'ticket',
                    ]
                ),
            ]
        );

        $this->expectException(ConcurrencyException::class);

        $eventStore->appendTo(
            $streamName,
            [
                Event::create(
                    new TicketWasRegistered('123', 'Johnny', 'alert'),
                    [
                        '_aggregate_id' => 1,
                        '_aggregate_version' => 1,
                        '_aggregate_type' => 'ticket',
                    ]
                ),
            ]
        );
    }

    public function test_fetching_with_pagination()
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getGateway(EventStore::class);

        $streamName = Uuid::v7()->toRfc4122();
        $eventStore->create($streamName);
        $eventStore->appendTo(
            $streamName,
            [
                new TicketWasRegistered('123', 'Johnny', 'alert'),
                new TicketWasClosed('123'),
            ]
        );

        $events = $eventStore->load($streamName, fromNumber: 2, count: 1);

        $this->assertEquals(
            new TicketWasClosed('123'),
            $events[0]->getPayload()
        );
    }

    public function test_aggregates_are_stored_in_the_default_event_stream_table(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [
                new InProgressTicketList($this->getConnection()),
                new TicketEventConverter(),
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->sendCommand(new RegisterTicket('1', 'johny', 'alert'));

        $connection = $this->getConnection();

        $this->assertTrue(self::tableExists($connection, StreamTableRegistry::DEFAULT_STREAM));
        $this->assertFalse(self::tableExists($connection, '_' . sha1(Ticket::class)));
        $this->assertFalse(self::tableExists($connection, 'event_streams'));

        $tableManager = new EventStreamTableManager([StreamTableRegistry::DEFAULT_STREAM], true, true);
        $tableManager->dropTable($connection);

        $this->assertFalse(self::tableExists($connection, StreamTableRegistry::DEFAULT_STREAM));
    }
}
