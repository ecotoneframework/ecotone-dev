<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
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
            ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::KAFKA_PACKAGE, ])
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        channelName: 'channel1',
                        topicName: $topic1,
                        messageGroupId: $sharedGroupId  // Same group ID
                    ),
                    KafkaMessageChannelBuilder::create(
                        channelName: 'channel2',
                        topicName: $topic2,
                        messageGroupId: $sharedGroupId  // Same group ID - should fail
                    ),
                ]),
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
            ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::KAFKA_PACKAGE, ])
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        channelName: 'channel1',
                        topicName: $topic1,
                        messageGroupId: $groupId1
                    ),
                    KafkaMessageChannelBuilder::create(
                        channelName: 'channel2',
                        topicName: $topic2,
                        messageGroupId: $groupId2
                    ),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->assertNotNull($ecotoneLite);
    }
}
