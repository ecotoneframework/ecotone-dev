<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\Configuration\AmqpConfiguration;
use Ecotone\Api\Amqp\AmqpMessagePublisherConfiguration;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\MessagePublisher;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
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
final class PulseFeaturesTest extends AmqpMessagingTestCase
{
    public function test_rabbitmq_transactions_are_off_by_default_and_say_what_publishing_does_without_them(): void
    {
        $transactions = $this->features([])['RabbitMQ transactions'];

        self::assertFalse($transactions['enabled']);
        self::assertSame(['off', 'off', 'off'], [$transactions['settings']['Command bus'], $transactions['settings']['Asynchronous endpoints'], $transactions['settings']['Console commands']]);
        self::assertStringContainsString('is sent at once, even when the handler fails after publishing it', $transactions['hint']);
        self::assertSame('AmqpConfiguration::createWithDefaults()->withTransactionOnCommandBus(true)->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnConsoleCommands(true)', $transactions['configuration']);
    }

    public function test_rabbitmq_transactions_on_asynchronous_endpoints_and_publishers_are_reported_with_their_settings(): void
    {
        $features = $this->features([
            AmqpConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true),
            AmqpMessagePublisherConfiguration::create(MessagePublisher::class, 'orders')->withDefaultRoutingKey('order.placed'),
        ]);

        self::assertTrue($features['RabbitMQ transactions']['enabled']);
        self::assertSame('on', $features['RabbitMQ transactions']['settings']['Asynchronous endpoints']);
        self::assertStringContainsString('Each message an asynchronous endpoint consumes runs inside a RabbitMQ transaction', $features['RabbitMQ transactions']['hint']);
        self::assertTrue($features['RabbitMQ message publishers']['enabled']);
        self::assertSame(["MessagePublisher: exchange 'orders', routing key 'order.placed', persistent"], $features['RabbitMQ message publishers']['handlers']);
    }

    public function test_without_publishers_the_publisher_feature_is_disabled(): void
    {
        $publishers = $this->features([])['RabbitMQ message publishers'];

        self::assertFalse($publishers['enabled']);
        self::assertSame([], $publishers['handlers']);
        self::assertStringContainsString('only through message channels', $publishers['hint']);
    }

    /**
     * @param object[] $extensionObjects
     * @param array<string, object> $services
     * @return array<string, array<string, mixed>>
     */
    private function features(array $extensionObjects, array $services = []): array
    {
        $handlers = new class () {
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
            containerOrAvailableServices: [$handlers, ...$this->getConnectionFactoryReferences(), ...$services],
            extensionObjects: [
                ...$channels,
                ...$extensionObjects,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::AMQP_PACKAGE],
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

        $packages = array_column(json_decode($pulse->snapshots[0], true, flags: JSON_THROW_ON_ERROR)['packages'], 'features', 'name');

        return array_column($packages['amqp'], null, 'name');
    }
}
