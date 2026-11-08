<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Kafka\KafkaConsumer;
use Ecotone\Api\Kafka\KafkaPublisherConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

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
final class PulseFeaturesTest extends TestCase
{
    public function test_each_kafka_message_publisher_is_listed_with_its_destination(): void
    {
        $publishers = $this->features([KafkaPublisherConfiguration::createWithDefaults('orders')])['Kafka message publishers'];

        self::assertTrue($publishers['enabled']);
        self::assertSame(["MessagePublisher: topic 'orders'"], $publishers['handlers']);
        self::assertStringContainsString('straight to Kafka through each MessagePublisher', $publishers['hint']);
    }

    public function test_without_publishers_the_kafka_publisher_feature_is_disabled(): void
    {
        $publishers = $this->features([])['Kafka message publishers'];

        self::assertFalse($publishers['enabled']);
        self::assertSame('0', $publishers['settings']['Publishers']);
    }

    public function test_each_kafka_consumer_is_listed_with_its_final_failure_strategy_tagged_default_or_its_own(): void
    {
        $handlers = new class () {
            #[KafkaConsumer('kafka.orders', 'orders')]
            public function orders(string $orderId): void
            {
            }

            #[KafkaConsumer('kafka.payments', 'payments', finalFailureStrategy: FinalFailureStrategy::RESEND)]
            public function payments(string $orderId): void
            {
            }
        };

        $consumers = array_column($this->snapshot([], handlers: $handlers)['consumers'], null, 'name');

        self::assertSame('inbound', $consumers['kafka.orders']['kind']);
        self::assertContains(['name' => 'Final failure strategy', 'value' => 'stop', 'scope' => 'global', 'handler' => null], $consumers['kafka.orders']['features']);
        self::assertContains(['name' => 'Final failure strategy', 'value' => 'resend', 'scope' => 'consumer-level config', 'handler' => null], $consumers['kafka.payments']['features']);
    }

    /**
     * @param object[] $extensionObjects
     * @param array<string, object> $services
     * @return array<string, array<string, mixed>>
     */
    private function features(array $extensionObjects, array $services = []): array
    {
        return array_column(array_column($this->snapshot($extensionObjects, $services)['packages'], 'features', 'name')['kafka'], null, 'name');
    }

    /**
     * @param object[] $extensionObjects
     * @param array<string, object> $services
     * @return array<string, mixed>
     */
    private function snapshot(array $extensionObjects, array $services = [], ?object $handlers = null): array
    {
        $handlers ??= new class () {
            #[CommandHandler('order.place')]
            public function place(string $orderId): void
            {
            }
        };
        $pulse = new class () {
            public array $snapshots = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }
        };
        $channels = [SimpleMessageChannelBuilder::createQueueChannel('order_channel'), SimpleMessageChannelBuilder::createQueueChannel('pulse_channel')];
        $orderService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handlers::class],
            containerOrAvailableServices: [$handlers, ...$services],
            extensionObjects: [
                ...$channels,
                ...$extensionObjects,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $pulseService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$pulse::class],
            containerOrAvailableServices: [$pulse],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        return json_decode($pulse->snapshots[0], true, flags: JSON_THROW_ON_ERROR);
    }
}
