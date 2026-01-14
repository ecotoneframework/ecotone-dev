<?php

declare(strict_types=1);

namespace Integration;

use Ecotone\EventSourcing\Database\EventStreamTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\Prooph\LazyProophEventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Modelling\Event;
use Enqueue\Dbal\DbalConnectionFactory;
use Ramsey\Uuid\Uuid;
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
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getGateway(EventStore::class);

        $streamName = Uuid::uuid4()->toString();
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

    public function test_storing_for_simple_stream()
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getGateway(EventStore::class);

        $streamName = Uuid::uuid4()->toString();
        $eventStore->create($streamName, streamMetadata: [
            LazyProophEventStore::PERSISTENCE_STRATEGY_METADATA => 'simple',
        ]);
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

    public function test_storing_same_event_for_simple_stream()
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getGateway(EventStore::class);

        $streamName = Uuid::uuid4()->toString();
        $eventStore->create($streamName, streamMetadata: [
            LazyProophEventStore::PERSISTENCE_STRATEGY_METADATA => 'simple',
        ]);
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

        $events = $eventStore->load($streamName);
        $this->assertCount(2, $events);
    }

    public function test_storing_same_event_for_partioned_stream()
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getGateway(EventStore::class);

        $streamName = Uuid::uuid4()->toString();
        $eventStore->create($streamName, streamMetadata: [
            LazyProophEventStore::PERSISTENCE_STRATEGY_METADATA => 'partition',
        ]);
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
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getGateway(EventStore::class);

        $streamName = Uuid::uuid4()->toString();
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
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getGateway(EventStore::class);

        $streamName = Uuid::uuid4()->toString();
        $eventStore->create($streamName, streamMetadata: [
            LazyProophEventStore::PERSISTENCE_STRATEGY_METADATA => 'simple',
        ]);
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

    public function test_deleting_event_stream_table_also_deletes_stream_tables(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [
                new InProgressTicketList($this->getConnection()),
                new TicketEventConverter(),
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->sendCommand(new RegisterTicket('1', 'johny', 'alert'));

        $connection = $this->getConnection();
        $eventStreamsTable = LazyProophEventStore::DEFAULT_STREAM_TABLE;
        $streamTableName = '_' . sha1(Ticket::class);

        $this->assertTrue(self::tableExists($connection, $eventStreamsTable));
        $this->assertTrue(self::tableExists($connection, $streamTableName));

        $tableManager = new EventStreamTableManager($eventStreamsTable, true, true);
        $tableManager->dropTable($connection);

        $this->assertFalse(self::tableExists($connection, $streamTableName));
        $this->assertFalse(self::tableExists($connection, $eventStreamsTable));
    }
}
