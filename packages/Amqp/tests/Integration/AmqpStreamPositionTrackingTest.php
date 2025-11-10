<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\AmqpStreamChannelBuilder;
use Ecotone\Lite\Test\TestConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Consumer\InMemory\InMemoryConsumerPositionTracker;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnection;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\Order\OrderService;

/**
 * Tests for AMQP Stream position tracking
 * licence Apache-2.0
 * @internal
 */
final class AmqpStreamPositionTrackingTest extends AmqpMessagingTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        if (getenv('AMQP_IMPLEMENTATION') !== 'lib') {
            $this->markTestSkipped('Stream tests require AMQP lib');
        }
    }

    public function test_resuming_from_last_committed_position_after_restart()
    {
        $channelName = 'orders';
        $queueName = 'stream_position_' . Uuid::uuid4()->toString();

        // Shared position tracker between both instances
        $sharedPositionTracker = new InMemoryConsumerPositionTracker();
        $orderService = new OrderService();

        // First application instance - process some messages
        $ecotoneLite1 = $this->bootstrapForTesting(
            [OrderService::class],
            [
                $orderService,
                ConsumerPositionTracker::class => $sharedPositionTracker,
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    TestConfiguration::createWithDefaults()
                        ->withInMemoryConsumerPositionTracker(false), // Disable default, use our shared instance
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        channelName: $channelName,
                        startPosition: 'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ),
                ])
        );

        // Send 5 messages
        $ecotoneLite1->getCommandBus()->sendWithRouting('order.register', 'msg1');
        $ecotoneLite1->getCommandBus()->sendWithRouting('order.register', 'msg2');
        $ecotoneLite1->getCommandBus()->sendWithRouting('order.register', 'msg3');
        $ecotoneLite1->getCommandBus()->sendWithRouting('order.register', 'msg4');
        $ecotoneLite1->getCommandBus()->sendWithRouting('order.register', 'msg5');

        // Run consumer to process messages - will process all available within time limit
        $ecotoneLite1->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $orders = $ecotoneLite1->getQueryBus()->sendWithRouting('order.getOrders');
        $processedCount = count($orders);
        $this->assertGreaterThan(0, $processedCount, 'Should have processed at least one message');

        // Check that position was committed
        // Position is tracked using combined consumer ID: endpointId:queueName
        $committedPosition = $sharedPositionTracker->loadPosition($channelName);
        $this->assertNotNull($committedPosition, 'Position should be committed after processing messages');

        // Second application instance - should resume from where first left off
        $ecotoneLite2 = $this->bootstrapForTesting(
            [OrderService::class],
            [
                $orderService,
                ConsumerPositionTracker::class => $sharedPositionTracker, // Same shared instance
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    TestConfiguration::createWithDefaults()
                        ->withInMemoryConsumerPositionTracker(false), // Disable default, use our shared instance
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        channelName: $channelName,
                        startPosition: 'first', // Will be overridden by committed position
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ),
                ])
        );

        // Run consumer - should process remaining messages
        $ecotoneLite2->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $orders = $ecotoneLite2->getQueryBus()->sendWithRouting('order.getOrders');
        $processedCount = count($orders);

        // Total processed should be 5 (no duplicates)
        $this->assertEquals(5, $processedCount, 'Should have processed all 5 messages across both instances without duplicates');
    }
}
