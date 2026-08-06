<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Dbal\OutboxForwardingMessageChannel;
use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;

/**
 * licence Enterprise
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class CombinedChannelForwardingTest extends TestCase
{
    public function test_batch_forwarding_configuration_for_kafka_source_channel_fails_at_compile_time(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);

        $uniqueId = Uuid::v7()->toRfc4122();
        EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    OutboxForwardingMessageChannel::create('orders', 'kafkaOutbox', 'orderProcessing'),
                    KafkaMessageChannelBuilder::create('kafkaOutbox', topicName: $uniqueId, messageGroupId: $uniqueId),
                    SimpleMessageChannelBuilder::createQueueChannel('orderProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
