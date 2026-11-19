<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\DynamicMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
use Ecotone\Api\Kafka\KafkaConsumer;
use Ecotone\Api\Kafka\KafkaMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

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
#[RunTestsInSeparateProcesses]
final class StreamingChannelValidationTest extends TestCase
{
    public function test_kafka_channels_cannot_share_same_message_group_id(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Message group ID');
        $this->expectExceptionMessage('is already used by channel');
        $this->expectExceptionMessage('Each Message Channel must have a unique message group ID to maintain processing isolation');

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
        $topic1 = 'topic1-' . Uuid::v7()->toRfc4122();
        $topic2 = 'topic2-' . Uuid::v7()->toRfc4122();

        EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [
                $handler,
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults(),
            ],
            extensionObjects: [
                KafkaMessageChannelBuilder::create(channelName:'channel1', topicName:$topic1, messageGroupId:$sharedGroupId),
                KafkaMessageChannelBuilder::create(channelName:'channel2', topicName:$topic2, messageGroupId:$sharedGroupId),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE, ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_kafka_channels_can_have_different_message_group_ids(): void
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
        $topic1 = 'topic1-' . Uuid::v7()->toRfc4122();
        $topic2 = 'topic2-' . Uuid::v7()->toRfc4122();

        // This should work fine - different group IDs
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [
                $handler,
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults(),
            ],
            extensionObjects: [
                KafkaMessageChannelBuilder::create(channelName:'channel1', topicName:$topic1, messageGroupId:$groupId1),
                KafkaMessageChannelBuilder::create(channelName:'channel2', topicName:$topic2, messageGroupId:$groupId2),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE, ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->assertNotNull($ecotoneLite);
    }

    public function test_kafka_channel_consumer_with_coroutines_in_its_polling_metadata_is_refused_when_the_application_boots(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Consumer `consumer1` is set to consume on 2 coroutines, but it reads a stream, `channel1` (a Kafka topic, a RabbitMQ stream or an in-memory streaming channel, directly or through a dynamic channel; a dynamic channel with a custom receiving strategy counts as reading every stream channel of the application), where the position of the last handled message is what gets committed. '
            . 'A coroutine finishing a later message would commit past an earlier message another coroutine is still handling, so that message is never handled when the process stops, and the messages are handled out of order. '
        );

        $handler = new class () {
            #[InternalHandler(inputChannelName: 'channel1', endpointId: 'consumer1')]
            public function handle(string $payload): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [
                $handler,
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults(),
            ],
            extensionObjects: [
                KafkaMessageChannelBuilder::create(channelName: 'channel1', topicName: 'topic1-' . Uuid::v7()->toRfc4122(), messageGroupId: 'group-' . Uuid::v7()->toRfc4122()),
                PollingMetadata::create('consumer1')->withCoroutines(2),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_dynamic_channel_over_kafka_channels_with_coroutines_in_its_polling_metadata_is_refused_when_the_application_boots(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Consumer `async` is set to consume on 2 coroutines, but it reads a stream, `async_tenant_a`, `async_tenant_b` (a Kafka topic, a RabbitMQ stream or an in-memory streaming channel, directly or through a dynamic channel; a dynamic channel with a custom receiving strategy counts as reading every stream channel of the application), where the position of the last handled message is what gets committed. '
        );

        $handler = new class () {
            #[Asynchronous('async')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function handle(string $payload): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [
                $handler,
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults(),
            ],
            extensionObjects: [
                DynamicMessageChannelBuilder::createRoundRobin('async', ['async_tenant_a', 'async_tenant_b']),
                KafkaMessageChannelBuilder::create(channelName: 'async_tenant_a', topicName: 'topic-a-' . Uuid::v7()->toRfc4122(), messageGroupId: 'group-a-' . Uuid::v7()->toRfc4122()),
                KafkaMessageChannelBuilder::create(channelName: 'async_tenant_b', topicName: 'topic-b-' . Uuid::v7()->toRfc4122(), messageGroupId: 'group-b-' . Uuid::v7()->toRfc4122()),
                PollingMetadata::create('async')->withCoroutines(2),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_kafka_consumer_is_refused_to_consume_on_coroutines_passed_when_running_it(): void
    {
        $handler = new class () {
            #[KafkaConsumer('orders_consumer', 'orders')]
            public function handle(string $payload): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [
                $handler,
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults(),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Consumer `orders_consumer` is set to consume on 2 coroutines, but it reads a stream (a Kafka topic, a RabbitMQ stream or an in-memory streaming channel, directly or through a dynamic channel; a dynamic channel with a custom receiving strategy counts as reading every stream channel of the application), where the position of the last handled message is what gets committed. '
        );

        $ecotone->run('orders_consumer', ExecutionPollingMetadata::createWithTestingSetup()->withCoroutines(2));
    }

    public function test_kafka_consumer_with_coroutines_in_its_polling_metadata_is_refused_when_the_application_boots(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Consumer `orders_consumer` is set to consume on 2 coroutines, but it reads a stream (a Kafka topic, a RabbitMQ stream or an in-memory streaming channel, directly or through a dynamic channel; a dynamic channel with a custom receiving strategy counts as reading every stream channel of the application), where the position of the last handled message is what gets committed. '
            . 'A coroutine finishing a later message would commit past an earlier message another coroutine is still handling, so that message is never handled when the process stops, and the messages are handled out of order. '
        );

        $handler = new class () {
            #[KafkaConsumer('orders_consumer', 'orders')]
            public function handle(string $payload): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [
                $handler,
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults(),
            ],
            extensionObjects: [
                PollingMetadata::create('orders_consumer')->withCoroutines(2),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
