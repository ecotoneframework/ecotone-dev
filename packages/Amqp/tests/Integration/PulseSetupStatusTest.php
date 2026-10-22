<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

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
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Test\LicenceTesting;
use Exception;
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
final class PulseSetupStatusTest extends AmqpMessagingTestCase
{
    public const QUEUE = 'pulse_setup_status_orders';

    private object $pulse;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;

    public function test_a_queue_that_was_never_declared_is_reported_as_not_set_up_until_the_channel_setup_creates_it(): void
    {
        $this->cleanUpQueue();
        $this->bootstrap();

        self::assertFalse($this->setUpOf($this->requestSnapshot()));

        $this->orderService->getGateway(ConsoleCommandRunner::class)->execute('ecotone:migration:channel:setup', ['initialize' => true]);

        self::assertTrue($this->setUpOf($this->requestSnapshot()));
        $this->cleanUpQueue();
    }

    private function setUpOf(array $snapshot): ?bool
    {
        return array_column($snapshot['channels'], null, 'name')[self::QUEUE]['setUp'];
    }

    private function bootstrap(): void
    {
        $orders = new class () {
            #[Asynchronous(PulseSetupStatusTest::QUEUE)]
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
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$orders::class],
            containerOrAvailableServices: [$orders, ...$this->getConnectionFactoryReferences()],
            extensionObjects: [
                ...$channels,
                AmqpBackedMessageChannelBuilder::create(self::QUEUE)->withAutoDeclare(false),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::CORE_PACKAGE, ModulePackageList::AMQP_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $this->pulseService = $this->bootstrapFlowTesting(
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

    private function cleanUpQueue(): void
    {
        try {
            $context = $this->getCachedConnectionFactory()->createContext();
            $context->deleteQueue($context->createQueue(self::QUEUE));
        } catch (Exception) {
        }
    }
}
