<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Kafka\KafkaMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;

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
final class PulseChannelDepthTest extends ConnectionTestCase
{
    private object $pulse;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;

    public function test_a_kafka_channel_reports_the_messages_its_consumer_group_has_not_read_yet(): void
    {
        $this->bootstrap();
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'coffee');
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'tea');
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'water');

        $orders = $this->channelsByName($this->requestSnapshot())['kafka_orders'];

        self::assertSame(['ready' => 3, 'delayed' => null, 'inFlight' => null, 'consumers' => null, 'probeError' => null], $this->depthOf($orders));
    }

    public function test_the_lag_shrinks_as_the_consumer_group_commits_what_it_handled(): void
    {
        $this->bootstrap();
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'coffee');
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'tea');
        $this->orderService->run('kafka_orders', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, executionTimeLimitInMilliseconds: 4000));

        $orders = $this->channelsByName($this->requestSnapshot())['kafka_orders'];

        self::assertSame(1, $orders['ready']);
    }

    public function test_a_channel_whose_topic_does_not_exist_yet_reports_why(): void
    {
        $this->bootstrap();

        $orders = $this->channelsByName($this->requestSnapshot())['kafka_orders'];

        self::assertNull($orders['ready']);
        self::assertStringContainsString("Kafka topic '{$this->topic}'", $orders['probeError']);
    }

    private string $topic;

    private function bootstrap(): void
    {
        $this->topic = 'pulse_depth_' . Uuid::v7()->toRfc4122();
        $orders = new class () {
            #[Asynchronous('kafka_orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $order): void
            {
            }
        };
        $this->pulse = new class () {
            public array $snapshots = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }
        };
        $channels = [SimpleMessageChannelBuilder::createQueueChannel('order_channel'), SimpleMessageChannelBuilder::createQueueChannel('pulse_channel')];
        $this->orderService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$orders::class],
            containerOrAvailableServices: [self::getConnection(), $orders],
            extensionObjects: [
                ...$channels,
                KafkaMessageChannelBuilder::create('kafka_orders', topicName: $this->topic, messageGroupId: $this->topic),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $this->pulseService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$this->pulse::class],
            containerOrAvailableServices: [$this->pulse],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function requestSnapshot(): array
    {
        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertCount(1, $this->pulse->snapshots);

        return json_decode($this->pulse->snapshots[0], true, flags: JSON_THROW_ON_ERROR);
    }

    private function channelsByName(array $snapshot): array
    {
        return array_column($snapshot['channels'], null, 'name');
    }

    private function depthOf(array $channel): array
    {
        return array_intersect_key($channel, array_flip(['ready', 'delayed', 'inFlight', 'consumers', 'probeError']));
    }
}
