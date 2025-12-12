<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\InternalHandler;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * licence Enterprise
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

        $sharedGroupId = 'shared-group-' . Uuid::uuid4()->toString();
        $topic1 = 'topic1-' . Uuid::uuid4()->toString();
        $topic2 = 'topic2-' . Uuid::uuid4()->toString();

        EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [
                $handler,
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
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

        $groupId1 = 'group1-' . Uuid::uuid4()->toString();
        $groupId2 = 'group2-' . Uuid::uuid4()->toString();
        $topic1 = 'topic1-' . Uuid::uuid4()->toString();
        $topic2 = 'topic2-' . Uuid::uuid4()->toString();

        // This should work fine - different group IDs
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [
                $handler,
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
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
