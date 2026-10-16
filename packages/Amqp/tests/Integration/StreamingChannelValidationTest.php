<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Api\Amqp\AmqpLibConnectionFactory as AmqpLibConnection;
use Ecotone\Api\Amqp\AmqpQueue;
use Ecotone\Api\Amqp\AmqpStreamChannelBuilder;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
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

        $sharedGroupId = 'shared-group-' . Uuid::v7()->toRfc4122();
        $queue1 = 'queue1-' . Uuid::v7()->toRfc4122();
        $queue2 = 'queue2-' . Uuid::v7()->toRfc4122();

        $this->bootstrapFlowTesting(
            [$handler::class],
            [
                $handler,
                ...$this->getConnectionFactoryReferences(),
            ],
            extensionObjects: [
                AmqpQueue::createStreamQueue($queue1),
                AmqpQueue::createStreamQueue($queue2),
                AmqpStreamChannelBuilder::create(channelName:'channel1', startPosition:'first', amqpConnectionReferenceName:AmqpLibConnection::class, queueName:$queue1, messageGroupId:$sharedGroupId),
                AmqpStreamChannelBuilder::create(channelName:'channel2', startPosition:'first', amqpConnectionReferenceName:AmqpLibConnection::class, queueName:$queue2, messageGroupId:$sharedGroupId),
            ],
            enabledModules: [ModulePackageList::AMQP_PACKAGE, ],
            licenceKey: LicenceTesting::VALID_LICENCE,
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

        $groupId1 = 'group1-' . Uuid::v7()->toRfc4122();
        $groupId2 = 'group2-' . Uuid::v7()->toRfc4122();
        $queue1 = 'queue1-' . Uuid::v7()->toRfc4122();
        $queue2 = 'queue2-' . Uuid::v7()->toRfc4122();

        // This should work fine - different group IDs
        $ecotoneLite = $this->bootstrapFlowTesting(
            [$handler::class],
            [
                $handler,
                ...$this->getConnectionFactoryReferences(),
            ],
            extensionObjects: [
                AmqpQueue::createStreamQueue($queue1),
                AmqpQueue::createStreamQueue($queue2),
                AmqpStreamChannelBuilder::create(channelName:'channel1', startPosition:'first', amqpConnectionReferenceName:AmqpLibConnection::class, queueName:$queue1, messageGroupId:$groupId1),
                AmqpStreamChannelBuilder::create(channelName:'channel2', startPosition:'first', amqpConnectionReferenceName:AmqpLibConnection::class, queueName:$queue2, messageGroupId:$groupId2),
            ],
            enabledModules: [ModulePackageList::AMQP_PACKAGE, ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->assertNotNull($ecotoneLite);
    }
}
