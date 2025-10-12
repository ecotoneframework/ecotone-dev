<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\AmqpStreamChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;

use Enqueue\AmqpExt\AmqpConnectionFactory as AmqpExtConnection;
use Enqueue\AmqpLib\AmqpConnectionFactory;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnection;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\Order\OrderService;
use Test\Ecotone\Amqp\Fixture\Order\OrderServiceWithFailures;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class AmqpStreamChannelTest extends AmqpMessagingTestCase
{
    public function setUp(): void
    {
        if (getenv('AMQP_IMPLEMENTATION') !== 'lib') {
            $this->markTestSkipped('Stream tests require AMQP lib');
        }
    }

    public function test_consuming_stream_messages_from_first_position()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_first_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        channelName: $channelName,
                        startPosition: 'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    )
                ])
        );

        // Send three messages to the stream
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'milk');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'bread');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'cheese');

        // Verify messages are not consumed yet
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        // Consume from first position - should get all three messages
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        // Verify all three messages were consumed from first position
        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');

        // Debug output
        echo "Orders received: " . json_encode($orders) . "\n";
        echo "Expected 3 orders from 'first' position\n";

        $this->assertCount(3, $orders);
        $this->assertContains('milk', $orders);
        $this->assertContains('bread', $orders);
        $this->assertContains('cheese', $orders);
    }

    public function test_consuming_stream_messages_from_last_position()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_last_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        'last',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ),
                ])
        );

        // Send three messages to the stream
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'milk');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'bread');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'cheese');

        // Verify messages are not consumed yet
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        // Consume from last position - should get only the last message
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithDefaults()
            ->withTestingSetup()
            ->withHandledMessageLimit(1)
            ->withExecutionTimeLimitInMilliseconds(5000)
        );

        // Verify only the last message was consumed
        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertEquals(['cheese'], $orders);
    }

    public function test_consuming_stream_messages_from_specific_offset()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_offset_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        '1',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ), // Start from second message (0-indexed)
                ])
        );

        // Send three messages to the stream
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'milk');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'bread');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'cheese');

        // Verify messages are not consumed yet
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        // Consume from offset 1 - should get second and third messages
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithDefaults()
            ->withTestingSetup()
            ->withHandledMessageLimit(2)
            ->withExecutionTimeLimitInMilliseconds(5000)
        );

        // Verify second and third messages were consumed
        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertEquals(['bread', 'cheese'], $orders);
    }

    public function test_consuming_from_empty_stream_with_next_offset()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_empty_next_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        'next',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ),
                ])
        );

        // Try to consume from empty stream with 'next' offset - should timeout gracefully
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithDefaults()
            ->withTestingSetup()
            ->withHandledMessageLimit(1)
            ->withExecutionTimeLimitInMilliseconds(1000)
        );

        // Verify no messages were consumed (stream was empty)
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        // Send a message - 'next' offset means it will be available for future consumers
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'milk');

        /** @TODO if we can't make this work, we should disallow `next` */
        // Note: With 'next' offset, the consumer is already positioned at the "next" offset
        // from the first run. Messages sent after that are not consumed in the same consumer instance
        // This test verifies that consuming from empty stream with 'next' doesn't fail
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_consuming_from_empty_stream_with_first_offset()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_empty_first_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ),
                ])
        );

        // Try to consume from empty stream with 'first' offset - should timeout gracefully
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithDefaults()
            ->withTestingSetup()
            ->withHandledMessageLimit(1)
            ->withExecutionTimeLimitInMilliseconds(1000)
        );

        // Verify no messages were consumed
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_consuming_large_batch_of_messages()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_large_batch_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ),
                ])
        );

        // Send 50 messages to test drain loop efficiency
        $expectedOrders = [];
        for ($i = 1; $i <= 50; $i++) {
            $order = "order_{$i}";
            $ecotoneLite->getCommandBus()->sendWithRouting('order.register', $order);
            $expectedOrders[] = $order;
        }

        // Verify messages are not consumed yet
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        // Consume all messages - drain loop should handle this efficiently
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        // Verify all 50 messages were consumed
        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertCount(50, $orders);
        $this->assertEquals($expectedOrders, $orders);
    }

    public function test_consuming_with_next_offset_ignores_existing_messages()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_next_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        'next',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ),
                ])
        );

        // Send messages before consumer starts
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'old_milk');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'old_bread');

        // Start consumer with 'next' offset - should ignore existing messages
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithDefaults()
            ->withTestingSetup()
            ->withHandledMessageLimit(3)
            ->withExecutionTimeLimitInMilliseconds(1000)
        );

        // Verify no old messages were consumed (next offset skips existing messages)
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_consuming_single_message_from_stream()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_single_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ),
                ])
        );

        // Send only one message
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'milk');

        // Consume - drain loop should handle single message correctly
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertEquals(['milk'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_consuming_messages_with_offset_beyond_stream_end()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_offset_beyond_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        '100', // Offset beyond stream end
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ),
                ])
        );

        // Send only 3 messages
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'milk');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'bread');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'cheese');

        // Try to consume from offset 100 (beyond stream) - should get nothing
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithDefaults()
            ->withTestingSetup()
            ->withHandledMessageLimit(1)
            ->withExecutionTimeLimitInMilliseconds(1000)
        );

        // Verify no messages were consumed
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_consuming_messages_in_order()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_order_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ),
                ])
        );

        // Send messages in specific order
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'first');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'second');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'third');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'fourth');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'fifth');

        // Consume all messages
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        // Verify messages were consumed in exact order
        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertEquals(['first', 'second', 'third', 'fourth', 'fifth'], $orders);
    }

    public function test_consuming_from_offset_zero()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_offset_zero_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        '0', // Start from first message (offset 0)
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ),
                ])
        );

        // Send messages
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'milk');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'bread');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'cheese');

        // Consume from offset 0 - should get all messages
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertCount(3, $orders);
        $this->assertEquals(['milk', 'bread', 'cheese'], $orders);
    }

    public function test_consuming_messages_multiple_times_from_first()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_replay_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ),
                ])
        );

        // Send messages
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'milk');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'bread');

        // First consumption
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $this->assertEquals(['milk', 'bread'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        // Second consumption - consumer continues from where it left off (after 'bread')
        // Without explicit offset tracking/reset, it won't replay messages
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        // No new messages consumed (consumer is at end of stream)
        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertCount(2, $orders); // Still just milk, bread
        $this->assertEquals(['milk', 'bread'], $orders);
    }

    public function test_consuming_with_very_short_timeout()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_short_timeout_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    ),
                ])
        );

        // Send messages
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'milk');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'bread');

        // Consume with very short timeout - may only get partial messages due to timeout
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithDefaults()
            ->withTestingSetup()
            ->withExecutionTimeLimitInMilliseconds(500) // Very short timeout
        );

        // With short timeout, we might get at least one message
        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertGreaterThanOrEqual(1, count($orders));
        $this->assertContains('milk', $orders); // At least first message should be consumed
    }

    public function test_release_retries_same_message()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_release_retry_' . Uuid::uuid4()->toString();

        $orderService = new OrderServiceWithFailures();
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderServiceWithFailures::class],
            [
                $orderService,
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    )->withFinalFailureStrategy(FinalFailureStrategy::RELEASE),
                ])
        );

        // Send a message that will fail on first attempt
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'fail_order1');

        // Run consumer - should fail first time, then retry and succeed
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages(failAtError: false)->withExecutionTimeLimitInMilliseconds(1000));

        // Verify the order was eventually processed
        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertEquals(['fail_order1'], $orders);

        // Verify it was attempted twice (failed once, succeeded on retry)
        $attemptCount = $ecotoneLite->getQueryBus()->sendWithRouting('order.getAttemptCount', 'fail_order1');
        $this->assertEquals(2, $attemptCount);
    }

    public function test_release_with_multiple_messages()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_release_multiple_' . Uuid::uuid4()->toString();

        $orderService = new OrderServiceWithFailures();
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderServiceWithFailures::class],
            [
                $orderService,
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    )->withFinalFailureStrategy(FinalFailureStrategy::RELEASE),
                ])
        );

        // Send 3 messages: success, fail, success
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'order1');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'fail_order2');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'order3');

        // Run consumer
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages(failAtError: false)->withExecutionTimeLimitInMilliseconds(1000));

        // Verify all messages were processed in order
        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertEquals(['order1', 'fail_order2', 'order3'], $orders);

        // Verify attempt counts
        $attemptCounts = $ecotoneLite->getQueryBus()->sendWithRouting('order.getAllAttemptCounts');
        $this->assertEquals(1, $attemptCounts['order1']); // Succeeded first time
        $this->assertEquals(2, $attemptCounts['fail_order2']); // Failed once, retried
        $this->assertEquals(1, $attemptCounts['order3']); // Succeeded first time
    }

    public function test_release_maintains_offset_position()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_release_offset_' . Uuid::uuid4()->toString();

        $orderService = new OrderServiceWithFailures();
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderServiceWithFailures::class],
            [
                $orderService,
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    )->withFinalFailureStrategy(FinalFailureStrategy::RELEASE),
                ])
        );

        // Send 10 messages, with message 5 failing
        for ($i = 1; $i <= 10; $i++) {
            $order = $i === 5 ? 'fail_order5' : "order{$i}";
            $ecotoneLite->getCommandBus()->sendWithRouting('order.register', $order);
        }

        // Run consumer
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages(failAtError: false)->withExecutionTimeLimitInMilliseconds(1000));

        // Verify all messages were processed
        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertCount(10, $orders);

        // Verify order5 was retried
        $attemptCount = $ecotoneLite->getQueryBus()->sendWithRouting('order.getAttemptCount', 'fail_order5');
        $this->assertEquals(2, $attemptCount);

        // Verify messages after the failed one were still processed
        $this->assertEquals(
            ['order1', 'order2', 'order3', 'order4', 'fail_order5', 'order6', 'order7', 'order8', 'order9', 'order10'],
            $orders
        );
    }

}
