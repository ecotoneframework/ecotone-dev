<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Closure;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
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
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use Interop\Amqp\Impl\AmqpQueue;
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
final class PulseChannelDepthTest extends AmqpMessagingTestCase
{
    private object $pulse;
    private object $orders;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;
    private string $ordersQueue;
    private string $requestQueue;
    private string $ghostQueue;

    public function test_an_amqp_channel_reports_its_ready_messages_and_leaves_what_the_broker_does_not_tell_null(): void
    {
        $this->bootstrap();
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'coffee');
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'tea');

        $orders = $this->channelsByName($this->requestSnapshot())['orders'];

        self::assertSame(2, $orders['ready']);
        self::assertNull($orders['delayed']);
        self::assertNull($orders['inFlight']);
        self::assertNull($orders['consumers']);
        self::assertNull($orders['probeError']);
    }

    public function test_a_consumer_attached_to_the_queue_is_counted_while_polling_consumers_stay_unknown(): void
    {
        if (! $this->isAmqpLib()) {
            self::markTestSkipped('ext-amqp reports the message count of a queue but not its consumers.');
        }
        $this->bootstrap();
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'coffee');
        $channel = self::getRabbitConnectionFactory()->createContext()->getLibChannel();
        $channel->basic_consume($this->ordersQueue, '', false, true, false, false, static function (): void {
        });

        $orders = $this->channelsByName($this->requestSnapshot())['orders'];

        self::assertSame(1, $orders['consumers']);
        $channel->close();
    }

    public function test_a_queue_that_does_not_exist_reports_why_and_the_next_request_is_still_answered(): void
    {
        $this->bootstrap();
        $this->orderService->getGateway(CommandBus::class)->sendWithRouting('order.place', 'coffee');
        self::getRabbitConnectionFactory()->createContext()->deleteQueue(new AmqpQueue($this->ghostQueue));

        $first = $this->requestSnapshot();
        $second = $this->requestSnapshot();

        foreach ([$first, $second] as $snapshot) {
            $channels = $this->channelsByName($snapshot);
            self::assertNull($channels['ghost']['ready']);
            self::assertStringContainsString($this->ghostQueue, $channels['ghost']['probeError']);
            self::assertNull($channels['orders']['probeError']);
            self::assertSame(1, $channels['orders']['ready']);
        }
    }

    public function tearDown(): void
    {
        foreach ([$this->ordersQueue ?? null, $this->requestQueue ?? null] as $queue) {
            if ($queue !== null) {
                self::getRabbitConnectionFactory()->createContext()->deleteQueue(new AmqpQueue($queue));
            }
        }
    }

    private function isAmqpLib(): bool
    {
        return getenv('AMQP_IMPLEMENTATION') === 'lib';
    }

    private function bootstrap(): void
    {
        $this->ordersQueue = 'pulse_orders_' . Uuid::v7()->toRfc4122();
        $this->requestQueue = 'pulse_request_' . Uuid::v7()->toRfc4122();
        $this->ghostQueue = 'pulse_ghost_' . Uuid::v7()->toRfc4122();
        $this->orders = new class () {
            public ?Closure $whileHandling = null;

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $order): void
            {
                if ($this->whileHandling !== null) {
                    ($this->whileHandling)();
                }
            }

            #[Asynchronous('ghost')]
            #[InternalHandler('order.ghost', endpointId: 'order.ghost.endpoint')]
            public function ghost(string $order): void
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
        $replyChannel = SimpleMessageChannelBuilder::createQueueChannel('pulse_channel');
        $this->orderService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$this->orders::class],
            containerOrAvailableServices: [...$this->getConnectionFactoryReferences(), $this->orders],
            extensionObjects: [
                $replyChannel,
                AmqpBackedMessageChannelBuilder::create('order_channel', queueName: $this->requestQueue),
                AmqpBackedMessageChannelBuilder::create('orders', queueName: $this->ordersQueue)->withAutoDeclare(true),
                AmqpBackedMessageChannelBuilder::create('ghost', queueName: $this->ghostQueue)->withAutoDeclare(false),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::AMQP_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $this->pulseService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$this->pulse::class],
            containerOrAvailableServices: [...$this->getConnectionFactoryReferences(), $this->pulse],
            extensionObjects: [
                $replyChannel,
                AmqpBackedMessageChannelBuilder::create('order_channel', queueName: $this->requestQueue),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::AMQP_PACKAGE],
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
}
