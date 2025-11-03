<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Channel;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\InternalHandler;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Consumer\InMemory\InMemoryConsumerPositionTracker;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class InMemorySharedChannelTest extends TestCase
{
    public function test_single_consumer_consumes_messages_in_correct_order(): void
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

        // Send 3 messages
        $ecotoneLite->sendDirectToChannel('shared_channel', 'message1');
        $ecotoneLite->sendDirectToChannel('shared_channel', 'message2');
        $ecotoneLite->sendDirectToChannel('shared_channel', 'message3');

        // Run consumer 3 times with limit of 1
        $ecotoneLite->run('consumer1', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['message1'], $ecotoneLite->sendQueryWithRouting('getConsumed'));

        $ecotoneLite->run('consumer1', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['message1', 'message2'], $ecotoneLite->sendQueryWithRouting('getConsumed'));

        $ecotoneLite->run('consumer1', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['message1', 'message2', 'message3'], $ecotoneLite->sendQueryWithRouting('getConsumed'));
    }

    public function test_two_consumers_track_positions_independently(): void
    {
        $positionTracker = new InMemoryConsumerPositionTracker();

        $handler1 = new class {
            private array $consumed = [];

            #[InternalHandler(inputChannelName: 'shared_channel', endpointId: 'consumer1')]
            public function handle(string $payload): void
            {
                $this->consumed[] = $payload;
            }

            #[QueryHandler('getConsumed1')]
            public function getConsumed(): array
            {
                return $this->consumed;
            }
        };

        $handler2 = new class {
            private array $consumed = [];

            #[InternalHandler(inputChannelName: 'shared_channel', endpointId: 'consumer2')]
            public function handle(string $payload): void
            {
                $this->consumed[] = $payload;
            }

            #[QueryHandler('getConsumed2')]
            public function getConsumed(): array
            {
                return $this->consumed;
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$handler1::class, $handler2::class],
            [$handler1, $handler2, ConsumerPositionTracker::class => $positionTracker],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createShared('shared_channel'),
                    PollingMetadata::create('consumer1')->setHandledMessageLimit(1),
                    PollingMetadata::create('consumer2')->setHandledMessageLimit(1),
                ])
        );

        // Send 3 messages
        $ecotoneLite->sendDirectToChannel('shared_channel', 'message1');
        $ecotoneLite->sendDirectToChannel('shared_channel', 'message2');
        $ecotoneLite->sendDirectToChannel('shared_channel', 'message3');

        // Consumer1 consumes first message
        $ecotoneLite->run('consumer1', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['message1'], $ecotoneLite->sendQueryWithRouting('getConsumed1'));
        $this->assertEquals([], $ecotoneLite->sendQueryWithRouting('getConsumed2'));

        // Consumer2 consumes first two messages
        $ecotoneLite->run('consumer2', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $ecotoneLite->run('consumer2', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['message1'], $ecotoneLite->sendQueryWithRouting('getConsumed1'));
        $this->assertEquals(['message1', 'message2'], $ecotoneLite->sendQueryWithRouting('getConsumed2'));

        // Consumer1 consumes second and third messages
        $ecotoneLite->run('consumer1', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $ecotoneLite->run('consumer1', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['message1', 'message2', 'message3'], $ecotoneLite->sendQueryWithRouting('getConsumed1'));
        $this->assertEquals(['message1', 'message2'], $ecotoneLite->sendQueryWithRouting('getConsumed2'));
    }

    public function test_throwing_exception_when_shared_channel_is_used_with_async_event_handler(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Asynchronous handlers work in point-to-point manner, therefore shared channels cannot be used");

        $eventHandler = new class {
            #[Asynchronous('events_channel')]
            #[EventHandler(listenTo: 'UserRegistered', endpointId: 'sendEmail')]
            public function sendEmail(\stdClass $event): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$eventHandler::class],
            [$eventHandler],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createShared('events_channel'),
                ])
        );
    }
}

