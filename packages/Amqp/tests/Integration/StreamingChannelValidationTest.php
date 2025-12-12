<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\AmqpStreamChannelBuilder;
use Ecotone\Messaging\Attribute\InternalHandler;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnection;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class StreamingChannelValidationTest extends AmqpMessagingTestCase
{
    public function setUp(): void
    {
        if (getenv('AMQP_IMPLEMENTATION') !== 'lib') {
            $this->markTestSkipped('Stream tests require AMQP lib');
        }
    }

    public function test_amqp_stream_channels_cannot_share_same_message_group_id(): void
    {
        $this->expectException(ConfigurationException::class);

        $handler = new class () {
            #[InternalHandler(inputChannelName: 'channel1', endpointId: 'consumer1')]
            public function handle1(string $payload): void
            {
            }

            #[InternalHandler(inputChannelName: 'channel2', endpointId: 'consumer2')]
            public function handle2(string $payload): void
            {
            }
        };

        $sharedGroupId = 'shared-group-' . Uuid::uuid4()->toString();
        $queue1 = 'queue1-' . Uuid::uuid4()->toString();
        $queue2 = 'queue2-' . Uuid::uuid4()->toString();

        $this->bootstrapForTesting(
            [$handler::class],
            [
                $handler,
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queue1),
                    AmqpQueue::createStreamQueue($queue2),
                    AmqpStreamChannelBuilder::create(
                        channelName: 'channel1',
                        startPosition: 'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queue1,
                        messageGroupId: $sharedGroupId  // Same group ID
                    ),
                    AmqpStreamChannelBuilder::create(
                        channelName: 'channel2',
                        startPosition: 'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queue2,
                        messageGroupId: $sharedGroupId  // Same group ID - should fail
                    ),
                ])
        );
    }

    public function test_amqp_stream_channels_can_have_different_message_group_ids(): void
    {
        $handler = new class () {
            #[InternalHandler(inputChannelName: 'channel1', endpointId: 'consumer1')]
            public function handle1(string $payload): void
            {
            }

            #[InternalHandler(inputChannelName: 'channel2', endpointId: 'consumer2')]
            public function handle2(string $payload): void
            {
            }
        };

        $groupId1 = 'group1-' . Uuid::uuid4()->toString();
        $groupId2 = 'group2-' . Uuid::uuid4()->toString();
        $queue1 = 'queue1-' . Uuid::uuid4()->toString();
        $queue2 = 'queue2-' . Uuid::uuid4()->toString();

        // This should work fine - different group IDs
        $ecotoneLite = $this->bootstrapForTesting(
            [$handler::class],
            [
                $handler,
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queue1),
                    AmqpQueue::createStreamQueue($queue2),
                    AmqpStreamChannelBuilder::create(
                        channelName: 'channel1',
                        startPosition: 'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queue1,
                        messageGroupId: $groupId1
                    ),
                    AmqpStreamChannelBuilder::create(
                        channelName: 'channel2',
                        startPosition: 'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queue2,
                        messageGroupId: $groupId2
                    ),
                ])
        );

        $this->assertNotNull($ecotoneLite);
    }
}
