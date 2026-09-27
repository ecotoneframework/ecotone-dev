<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Config\EventStoreReference;
use Ecotone\EventSourcing\Database\EventStreamTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\Event;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class EventStreamAggregateQueryTest extends EventSourcingMessagingTestCase
{
    public function test_loading_events_by_aggregate_type_id_and_from_version(): void
    {
        $eventStore = $this->bootstrapEventStore();
        $streamName = Uuid::v7()->toRfc4122();
        $this->createStreamTable($streamName);

        $eventStore->appendTo($streamName, [
            Event::create(new TicketWasRegistered('123', 'Johnny', 'alert'), ['_aggregate_id' => '123', '_aggregate_type' => 'ticket', '_aggregate_version' => 1]),
            Event::create(new TicketWasClosed('123'), ['_aggregate_id' => '123', '_aggregate_type' => 'ticket', '_aggregate_version' => 2]),
            Event::create(new TicketWasRegistered('124', 'Bob', 'issue'), ['_aggregate_id' => '124', '_aggregate_type' => 'ticket', '_aggregate_version' => 1]),
        ]);

        $events = $eventStore->loadAggregateEvents($streamName, 'ticket', '123', fromVersion: 2);

        self::assertCount(1, $events);
        self::assertEquals(new TicketWasClosed('123'), $events[0]->getPayload());
    }

    public function test_loading_events_filtered_by_event_names(): void
    {
        $eventStore = $this->bootstrapEventStore();
        $streamName = Uuid::v7()->toRfc4122();
        $this->createStreamTable($streamName);

        $eventStore->appendTo($streamName, [
            Event::create(new TicketWasRegistered('123', 'Johnny', 'alert'), ['_aggregate_id' => '123', '_aggregate_type' => 'ticket', '_aggregate_version' => 1]),
            Event::create(new TicketWasClosed('123'), ['_aggregate_id' => '123', '_aggregate_type' => 'ticket', '_aggregate_version' => 2]),
        ]);

        $events = $eventStore->loadAggregateEvents($streamName, 'ticket', '123', eventNames: [TicketWasClosed::class]);

        self::assertCount(1, $events);
        self::assertEquals(new TicketWasClosed('123'), $events[0]->getPayload());
    }

    private function bootstrapEventStore(): EventStore
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        /** @var EventStore $eventStore */
        $eventStore = $ecotone->getServiceFromContainer(EventStoreReference::EVENT_STORE_INSTANCE);

        return $eventStore;
    }

    private function createStreamTable(string $streamName): void
    {
        (new EventStreamTableManager([$streamName], true, true))->createTable($this->getConnection());
    }
}
