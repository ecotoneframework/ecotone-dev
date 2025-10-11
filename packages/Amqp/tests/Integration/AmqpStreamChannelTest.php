<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\AmqpStreamChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;

use Enqueue\AmqpExt\AmqpConnectionFactory as AmqpExtConnection;
use Enqueue\AmqpLib\AmqpConnectionFactory;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnection;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\Order\OrderService;

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

    public function test_filtering_out_messages(): void
    {

    }
}
