<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\Attribute\Projection;
use Ecotone\Projecting\InMemory\InMemoryStreamSourceBuilder;

use function get_class;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class ProjectingTestCase extends TestCase
{
    public function test_asynchronous_projection(): void
    {
        // Given an asynchronous projection
        $projection = new #[Projection('test'), Asynchronous('async')] class {
            public array $handledEvents = [];
            #[EventHandler('*')]
            public function handle(array $event): void
            {
                $this->handledEvents[] = $event;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [get_class($projection)],
            [$projection],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->addExtensionObject($streamSource = new InMemoryStreamSourceBuilder())
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('async'))
        );

        $streamSource->append(Event::createWithType('test-event', ['name' => 'Test']));

        // When event is published, triggering the projection
        $ecotone->publishEventWithRoutingKey('trigger', []);

        // Then it is not handled until async channel is run
        $this->assertCount(0, $projection->handledEvents);
        $ecotone->run('async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertCount(1, $projection->handledEvents);
    }

    public function test_partitioned_projection(): void
    {
        // Given a partitioned projection
        $projection = new #[Projection('test', 'partitionHeader')] class {
            public array $handledEvents = [];
            #[EventHandler('*')]
            public function handle(array $event): void
            {
                $this->handledEvents[] = $event;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [get_class($projection)],
            [$projection],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
                ->addExtensionObject($streamSource = new InMemoryStreamSourceBuilder(partitionField: 'id'))
        );

        $streamSource->append(
            Event::createWithType('test-event', ['name' => 'Test'], ['id' => '1']),
            Event::createWithType('test-event', ['name' => 'Test'], ['id' => '2']),
            Event::createWithType('test-event', ['name' => 'Test'], ['id' => '1']),
        );

        // When event is published, triggering the projection
        $ecotone->publishEventWithRoutingKey('trigger', metadata: ['partitionHeader' => '1']);

        // Then only events from partition 1 are handled
        $this->assertCount(2, $projection->handledEvents);
    }

    public function test_asynchronous_partitioned_projection(): void
    {
        // Given a partitioned async projection
        $projection = new #[Projection('test', 'partitionHeader'), Asynchronous('async')] class {
            public array $handledEvents = [];
            #[EventHandler('*')]
            public function handle(array $event): void
            {
                $this->handledEvents[] = $event;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [get_class($projection)],
            [$projection],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->addExtensionObject($streamSource = new InMemoryStreamSourceBuilder(partitionField: 'id'))
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('async'))
        );

        $streamSource->append(
            Event::createWithType('test-event', ['name' => 'Test'], ['id' => '1']),
            Event::createWithType('test-event', ['name' => 'Test'], ['id' => '2']),
            Event::createWithType('test-event', ['name' => 'Test'], ['id' => '1']),
        );

        // When event is published, triggering the projection
        $ecotone->publishEventWithRoutingKey('trigger', metadata: ['partitionHeader' => '1']);

        // Then no event is handled until async channel is run
        $this->assertCount(0, $projection->handledEvents);
        $ecotone->run('async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertCount(2, $projection->handledEvents);
    }
}
