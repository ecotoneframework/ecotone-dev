<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Closure;
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
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use Exception;
use Test\Ecotone\Sqs\ConnectionTestCase;

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
final class PulseChannelDepthTest extends ConnectionTestCase
{
    private object $pulse;
    private object $orders;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;

    public function test_the_channel_reports_its_ready_and_delayed_messages(): void
    {
        $this->bootstrap();
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'coffee');
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'tea');
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'later', metadata: [MessageHeaders::DELIVERY_DELAY => 120_000]);

        $orders = $this->channelsByName($this->requestSnapshot())['pulse_depth_orders'];

        self::assertSame(['ready' => 2, 'delayed' => 1, 'inFlight' => 0, 'consumers' => null, 'probeError' => null], $this->depthOf($orders));
        self::assertTrue($orders['setUp']);
    }

    public function test_a_message_being_handled_is_reported_in_flight(): void
    {
        $this->bootstrap();
        $this->orders->whileHandling = function (): void {
            $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
            $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        };
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'coffee');
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'tea');

        $this->orderService->run('pulse_depth_orders', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $depth = $this->depthOf($this->channelsByName(json_decode($this->pulse->snapshots[0], true, flags: JSON_THROW_ON_ERROR))['pulse_depth_orders']);
        self::assertSame(1, $depth['ready']);
        self::assertSame(1, $depth['inFlight']);
    }

    public function test_a_queue_that_does_not_exist_yet_is_reported_as_not_set_up(): void
    {
        $this->bootstrap();

        $orders = $this->channelsByName($this->requestSnapshot())['pulse_depth_orders'];

        self::assertNull($orders['ready']);
        self::assertFalse($orders['setUp']);
        self::assertNull($orders['probeError']);
    }

    public function test_the_depth_drops_to_zero_once_the_messages_are_handled(): void
    {
        $this->bootstrap();
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'coffee');
        $this->orderService->run('pulse_depth_orders', ExecutionPollingMetadata::createWithTestingSetup());

        $depth = $this->depthOf($this->channelsByName($this->requestSnapshot())['pulse_depth_orders']);

        self::assertSame(['ready' => 0, 'delayed' => 0, 'inFlight' => 0, 'consumers' => null, 'probeError' => null], $depth);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $context = $this->getConnectionFactory()->createContext();
        try {
            $context->deleteQueue($context->createQueue('pulse_depth_orders'));
        } catch (Exception) {
        }
    }

    private function bootstrap(): void
    {
        $this->orders = new class () {
            public ?Closure $whileHandling = null;

            #[Asynchronous('pulse_depth_orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $order): void
            {
                if ($this->whileHandling !== null) {
                    ($this->whileHandling)();
                }
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
            classesToResolve: [$this->orders::class],
            containerOrAvailableServices: ['depth_connection' => $this->getConnectionFactory(), $this->orders],
            extensionObjects: [
                ...$channels,
                SqsBackedMessageChannelBuilder::create('pulse_depth_orders', 'depth_connection'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
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
        $received = count($this->pulse->snapshots);
        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertCount($received + 1, $this->pulse->snapshots);

        return json_decode($this->pulse->snapshots[$received], true, flags: JSON_THROW_ON_ERROR);
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
