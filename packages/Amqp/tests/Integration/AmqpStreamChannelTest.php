<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\AmqpStreamChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\TestConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpExt\AmqpConnectionFactory as AmqpExtConnection;
use Enqueue\AmqpLib\AmqpConnectionFactory;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnection;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\Order\OrderService;
use Test\Ecotone\Amqp\Fixture\Order\OrderServiceWithFailures;

/**
 * licence Enterprise
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

    public function test_throwing_exception_if_no_licence_for_amqp_stream_channel(): void
    {
        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue('test_queue'),
                    AmqpStreamChannelBuilder::create(
                        'test_channel',
                        'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: 'test_queue',
                    ),
                ])
        );
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
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

    public function test_resend_moves_failed_message_to_end()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_resend_' . Uuid::uuid4()->toString();

        $orderService = new OrderServiceWithFailures();
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderServiceWithFailures::class],
            [
                $orderService,
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        $channelName,
                        'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    )->withFinalFailureStrategy(FinalFailureStrategy::RESEND),
                ])
        );

        // Send 3 messages: success, fail, success
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'order1');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'fail_order2');
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'order3');

        // Run consumer
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages(failAtError: false)->withExecutionTimeLimitInMilliseconds(5000));

        // Verify all messages were processed
        // With RESEND: order1 succeeds, fail_order2 fails and goes to end, order3 succeeds, fail_order2 retried and succeeds
        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $attemptCounts = $ecotoneLite->getQueryBus()->sendWithRouting('order.getAllAttemptCounts');

        $this->assertEquals(['order1', 'order3', 'fail_order2'], $orders);

        // Verify attempt counts - fail_order2 should have been tried twice
        $this->assertEquals(1, $attemptCounts['order1']); // Succeeded first time
        $this->assertEquals(2, $attemptCounts['fail_order2']); // Failed once, retried at end and succeeded
        $this->assertEquals(1, $attemptCounts['order3']); // Succeeded first time
    }

    public function test_consuming_with_prefetch_limit_across_multiple_batches()
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_prefetch_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        channelName: $channelName,
                        startPosition: 'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    )
                        ->withPrefetchCount(1), // Set prefetch to 1
                ])
        );

        // Send first batch of 10 messages
        for ($i = 1; $i <= 10; $i++) {
            $ecotoneLite->getCommandBus()->sendWithRouting('order.register', "batch1_order_{$i}");
        }

        // Consume all messages from first batch
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertCount(10, $orders);

        // Send second batch of 10 messages
        for ($i = 1; $i <= 10; $i++) {
            $ecotoneLite->getCommandBus()->sendWithRouting('order.register', "batch2_order_{$i}");
        }

        // Consume all messages from second batch
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertCount(20, $orders);

        // Send third batch of 10 messages
        for ($i = 1; $i <= 10; $i++) {
            $ecotoneLite->getCommandBus()->sendWithRouting('order.register', "batch3_order_{$i}");
        }

        // Consume all messages from third batch
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertCount(30, $orders);

        // Verify all messages were consumed in order
        $expectedOrders = [];
        for ($batch = 1; $batch <= 3; $batch++) {
            for ($i = 1; $i <= 10; $i++) {
                $expectedOrders[] = "batch{$batch}_order_{$i}";
            }
        }
        $this->assertEquals($expectedOrders, $orders);
    }

    public function test_commit_interval_with_prefetch_count(): void
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_commit_interval_' . Uuid::uuid4()->toString();

        $sharedPositionTracker = new \Ecotone\Messaging\Consumer\InMemory\InMemoryConsumerPositionTracker();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
                \Ecotone\Messaging\Consumer\ConsumerPositionTracker::class => $sharedPositionTracker,
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        channelName: $channelName,
                        startPosition: 'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    )
                        ->withPrefetchCount(2)
                        ->withCommitInterval(2), // Commit every 2 messages,
                    TestConfiguration::createWithDefaults()->withInMemoryConsumerPositionTracker(false),
                ])
        );

        // Send 5 messages
        for ($i = 1; $i <= 5; $i++) {
            $ecotoneLite->getCommandBus()->sendWithRouting('order.register', "order_{$i}");
        }

        // Consume all messages
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        // Verify all messages were consumed
        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertEquals(['order_1', 'order_2', 'order_3', 'order_4', 'order_5'], $orders);

        // Verify position was committed at offsets 2, 4, and 5 (last message in batch)
        // The committed position is the NEXT offset to consume from
        $consumerId = $channelName . ':' . $queueName;
        $committedPosition = $sharedPositionTracker->loadPosition($consumerId);

        // After consuming 5 messages (offsets 0-4), the committed position should be 5 (next to consume)
        // With commitInterval=2, commits happen at messages 2, 4, and 5 (end of batch)
        $this->assertEquals('5', $committedPosition, 'Position should be committed at offset 5 (after last message)');
    }

    public function test_commit_interval_with_prefetch_count_lower_than_commit_interval(): void
    {
        $channelName = 'orders';
        $queueName = 'stream_queue_commit_interval_' . Uuid::uuid4()->toString();

        $sharedPositionTracker = new \Ecotone\Messaging\Consumer\InMemory\InMemoryConsumerPositionTracker();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
                \Ecotone\Messaging\Consumer\ConsumerPositionTracker::class => $sharedPositionTracker,
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        channelName: $channelName,
                        startPosition: 'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    )
                        ->withPrefetchCount(1)
                        ->withCommitInterval(2), // Commit every 2 messages,
                    TestConfiguration::createWithDefaults()->withInMemoryConsumerPositionTracker(false),
                ])
        );

        // Send 5 messages
        for ($i = 1; $i <= 5; $i++) {
            $ecotoneLite->getCommandBus()->sendWithRouting('order.register', "order_{$i}");
        }

        // Consume all messages
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        // Verify all messages were consumed
        $orders = $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders');
        $this->assertEquals(['order_1', 'order_2', 'order_3', 'order_4', 'order_5'], $orders);

        $consumerId = $channelName . ':' . $queueName;
        $committedPosition = $sharedPositionTracker->loadPosition($consumerId);
        $this->assertEquals('5', $committedPosition, 'Position should be committed at offset 5 (after last message)');
    }

    /**
     * @TODO requires refactor
     */
    public function test_commit_interval_with_single_message_polling_metadata(): void
    {
        $this->markTestSkipped("Requires passing PollingMetadata to Inbound Adapters");

        $channelName = 'orders';
        $queueName = 'stream_queue_single_poll_' . Uuid::uuid4()->toString();

        $sharedPositionTracker = new \Ecotone\Messaging\Consumer\InMemory\InMemoryConsumerPositionTracker();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
                \Ecotone\Messaging\Consumer\ConsumerPositionTracker::class => $sharedPositionTracker,
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueName),
                    AmqpStreamChannelBuilder::create(
                        channelName: $channelName,
                        startPosition: 'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueName,
                    )
                        ->withCommitInterval(3), // Commit every 3 messages,
                    TestConfiguration::createWithDefaults()->withInMemoryConsumerPositionTracker(false)
                ])
        );

        // Send 5 messages
        for ($i = 1; $i <= 5; $i++) {
            $ecotoneLite->getCommandBus()->sendWithRouting('order.register', "order_{$i}");
        }

        $consumerId = $channelName . ':' . $queueName;

        // Run consumer multiple times with single message limit
        // Message 1
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['order_1'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
        // Position should be committed at 1 (end of batch, even though commitInterval=3)
        $this->assertEquals('1', $sharedPositionTracker->loadPosition($consumerId));

        // Message 2
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['order_1', 'order_2'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
        // Position should be committed at 2 (end of batch)
        $this->assertEquals('2', $sharedPositionTracker->loadPosition($consumerId));

        // Message 3
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['order_1', 'order_2', 'order_3'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
        // Position should be committed at 3 (end of batch)
        $this->assertEquals('3', $sharedPositionTracker->loadPosition($consumerId));

        // Message 4
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['order_1', 'order_2', 'order_3', 'order_4'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
        // Position should be committed at 4 (end of batch)
        $this->assertEquals('4', $sharedPositionTracker->loadPosition($consumerId));

        // Message 5
        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['order_1', 'order_2', 'order_3', 'order_4', 'order_5'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
        // Position should be committed at 5 (end of batch)
        $this->assertEquals('5', $sharedPositionTracker->loadPosition($consumerId));
    }

}
