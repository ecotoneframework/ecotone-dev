<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Channel;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\InternalHandler;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Consumer\InMemory\InMemoryConsumerPositionTracker;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Modelling\Attribute\QueryHandler;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class SharedChannelConsumerRegistrationTest extends TestCase
{
    public function test_shared_channel_is_not_automatically_registered_as_consumer(): void
    {
        $positionTracker = new InMemoryConsumerPositionTracker();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [],
            [ConsumerPositionTracker::class => $positionTracker],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createShared('shared_channel'),
                ])
        );

        // Shared channel should NOT be in the list of consumers
        $this->assertNotContains('shared_channel', $ecotoneLite->list());
    }

    public function test_shared_channel_consumer_is_registered_when_used_by_handler(): void
    {
        $positionTracker = new InMemoryConsumerPositionTracker();

        $handler = new class {
            private array $consumed = [];

            #[InternalHandler(inputChannelName: 'shared_channel', endpointId: 'consumer1')]
            public function handle(string $payload): void
            {
                $this->consumed[] = $payload;
            }

            #[QueryHandler('getConsumed')]
            public function getConsumed(): array
            {
                return $this->consumed;
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, ConsumerPositionTracker::class => $positionTracker],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createShared('shared_channel'),
                    PollingMetadata::create('consumer1')->setHandledMessageLimit(1),
                ])
        );

        // Consumer should be registered when handler uses the shared channel
        $this->assertContains('consumer1', $ecotoneLite->list());
        
        // Verify it works
        $ecotoneLite->sendDirectToChannel('shared_channel', 'message1');
        $ecotoneLite->run('consumer1', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['message1'], $ecotoneLite->sendQueryWithRouting('getConsumed'));
    }

    public function test_regular_pollable_channel_is_automatically_registered_as_consumer(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [],
            [],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('regular_channel'),
                ])
        );

        // Regular pollable channel SHOULD be in the list of consumers
        $this->assertContains('regular_channel', $ecotoneLite->list());
    }
}

