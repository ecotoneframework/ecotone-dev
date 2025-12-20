<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\InMemory;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Converter;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\Attribute\Polling;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class InMemoryEventStoreRegistrationTest extends TestCase
{
    public function test_registers_in_memory_event_store_stream_source_when_pdo_event_sourcing_is_in_memory_mode(): void
    {
        // Given a polling projection (polling projections read from stream sources)
        $projection = new #[ProjectionV2('test_projection'), Polling('test_projection_poller')] class {
            public array $events = [];
            public int $callCount = 0;

            #[EventHandler]
            public function onEvent(TestEventForInMemoryMode $event): void
            {
                $this->callCount++;
                $this->events[] = ['id' => $event->id, 'name' => $event->name];
            }
        };

        // When bootstrapping with PdoEventSourcing in in-memory mode
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [TestEventForInMemoryMode::class, TestEventForInMemoryModeConverter::class, $projection::class],
            [$projection, new TestEventForInMemoryModeConverter()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->addExtensionObject(EventSourcingConfiguration::createInMemory())
        );

        // And adding events to event store using withEventStream
        $ecotone->withEventStream('test_stream', [
            Event::create(new TestEventForInMemoryMode(1, 'Event 1')),
            Event::create(new TestEventForInMemoryMode(2, 'Event 2')),
        ]);

        // When running the polling projection (it reads from the stream source)
        $ecotone->run('test_projection_poller', ExecutionPollingMetadata::createWithTestingSetup());

        // Then the projection should have consumed events from InMemoryEventStore
        $this->assertEquals(2, $projection->callCount, 'Event handler should have been called 2 times');
        $this->assertCount(2, $projection->events, 'Projection should have consumed 2 events');
        $this->assertEquals(['id' => 1, 'name' => 'Event 1'], $projection->events[0]);
        $this->assertEquals(['id' => 2, 'name' => 'Event 2'], $projection->events[1]);
    }
}

class TestEventForInMemoryMode
{
    public function __construct(public int $id = 0, public string $name = '')
    {
    }
}

class TestEventForInMemoryModeConverter
{
    #[Converter]
    public function from(TestEventForInMemoryMode $event): array
    {
        return ['id' => $event->id, 'name' => $event->name];
    }

    #[Converter]
    public function to(array $data): TestEventForInMemoryMode
    {
        return new TestEventForInMemoryMode($data['id'], $data['name']);
    }
}
