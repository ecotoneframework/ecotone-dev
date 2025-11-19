<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Consumer\InMemory\InMemoryConsumerPositionTracker;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\EventStreamingProjection;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class EventStreamingProjectionTest extends TestCase
{
    public function test_event_streaming_projection_consuming_from_streaming_channel(): void
    {
        $positionTracker = new InMemoryConsumerPositionTracker();

        // Given a projection that consumes from streaming channel
        $projection = new #[EventStreamingProjection('user_projection', 'streaming_channel')] class {
            public array $projectedUsers = [];

            #[EventHandler]
            public function onUserCreated(UserCreated $event): void
            {
                $this->projectedUsers[$event->id] = $event->name;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$projection::class, UserCreated::class],
            [$projection, ConsumerPositionTracker::class => $positionTracker],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withNamespaces(['Test\Ecotone\Projecting'])
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createStreamingChannel('streaming_channel', conversionMediaType: MediaType::createApplicationXPHP()),
                ])
        );

        // When events are sent directly to the streaming channel (bypassing gateway to avoid serialization)
        $channel = $ecotone->getMessageChannel('streaming_channel');
        $channel->send(MessageBuilder::withPayload(new UserCreated('user-1', 'John Doe'))
            ->setHeader(MessageHeaders::TYPE_ID, UserCreated::class)
            ->setContentType(MediaType::createApplicationXPHP())
            ->build());
        $channel->send(MessageBuilder::withPayload(new UserCreated('user-2', 'Jane Smith'))
            ->setHeader(MessageHeaders::TYPE_ID, UserCreated::class)
            ->setContentType(MediaType::createApplicationXPHP())
            ->build());

        // Then the projection should not have projected yet (polling mode)
        $this->assertCount(0, $projection->projectedUsers);

        // When we run the projection consumer (process 2 messages)
        $ecotone->run('user_projection', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2));

        // Then the projection should have projected the events
        $this->assertCount(2, $projection->projectedUsers);
        $this->assertEquals('John Doe', $projection->projectedUsers['user-1']);
        $this->assertEquals('Jane Smith', $projection->projectedUsers['user-2']);
    }

    public function test_event_streaming_projection_with_multiple_event_handlers_routed_by_name(): void
    {
        $positionTracker = new InMemoryConsumerPositionTracker();

        // Given a projection with two event handlers routed by event names
        $projection = new #[EventStreamingProjection('order_projection', 'streaming_channel')] class {
            public array $createdOrders = [];
            public array $completedOrders = [];

            #[EventHandler('order.created')]
            public function onOrderCreated(array $event): void
            {
                $this->createdOrders[] = $event;
            }

            #[EventHandler('order.completed')]
            public function onOrderCompleted(array $event): void
            {
                $this->completedOrders[] = $event;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$projection::class],
            [$projection, ConsumerPositionTracker::class => $positionTracker],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withNamespaces(['Test\Ecotone\Projecting'])
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createStreamingChannel('streaming_channel', conversionMediaType: MediaType::createApplicationXPHP()),
                ])
        );

        // When events are sent with different routing keys
        $channel = $ecotone->getMessageChannel('streaming_channel');
        $channel->send(MessageBuilder::withPayload(['orderId' => 'order-1', 'amount' => 100])
            ->setHeader(MessageHeaders::TYPE_ID, 'order.created')
            ->setContentType(MediaType::createApplicationXPHP())
            ->build());
        $channel->send(MessageBuilder::withPayload(['orderId' => 'order-2', 'amount' => 200])
            ->setHeader(MessageHeaders::TYPE_ID, 'order.created')
            ->setContentType(MediaType::createApplicationXPHP())
            ->build());
        $channel->send(MessageBuilder::withPayload(['orderId' => 'order-1', 'completedAt' => '2024-01-01'])
            ->setHeader(MessageHeaders::TYPE_ID, 'order.completed')
            ->setContentType(MediaType::createApplicationXPHP())
            ->build());

        // Then the projection should not have projected yet (polling mode)
        $this->assertCount(0, $projection->createdOrders);
        $this->assertCount(0, $projection->completedOrders);

        // When we run the projection consumer (process 3 messages)
        $ecotone->run('order_projection', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 3));

        // Then the projection should have routed events to correct handlers
        $this->assertCount(2, $projection->createdOrders);
        $this->assertCount(1, $projection->completedOrders);
        $this->assertEquals('order-1', $projection->createdOrders[0]['orderId']);
        $this->assertEquals('order-2', $projection->createdOrders[1]['orderId']);
        $this->assertEquals('order-1', $projection->completedOrders[0]['orderId']);
    }
}

// Test classes
class UserCreated
{
    public function __construct(
        public string $id,
        public string $name
    ) {
    }
}

