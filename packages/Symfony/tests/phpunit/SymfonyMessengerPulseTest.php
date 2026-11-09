<?php

declare(strict_types=1);

namespace Test;

use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Symfony\SymfonyMessengerMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
final class SymfonyMessengerPulseTest extends WebTestCase
{
    protected function tearDown(): void
    {
        restore_exception_handler();
        static::ensureKernelShutdown();
    }

    public function test_a_symfony_messenger_channel_reports_its_final_failure_strategy_to_pulse(): void
    {
        $snapshot = $this->snapshotWith([
            SymfonyMessengerMessageChannelBuilder::create('messenger_async'),
            SymfonyMessengerMessageChannelBuilder::create('amqp_async')->withFinalFailureStrategy(FinalFailureStrategy::STOP),
        ]);

        $failure = array_column(array_column($snapshot['packages'], 'features', 'name')['core'], null, 'name')['Final failure strategy'];
        self::assertSame('resend', $failure['settings']['Channel messenger_async']);
        self::assertSame('stop', $failure['settings']['Channel amqp_async']);
        $consumers = array_column($snapshot['consumers'], 'features', 'name');
        self::assertContains(['name' => 'Final failure strategy', 'value' => 'resend', 'scope' => 'global', 'handler' => null], $consumers['messenger_async']);
        self::assertContains(['name' => 'Final failure strategy', 'value' => 'stop', 'scope' => 'consumer-level config', 'handler' => null], $consumers['amqp_async']);
    }

    /**
     * @param object[] $extensionObjects
     * @return array<string, mixed>
     */
    private function snapshotWith(array $extensionObjects): array
    {
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
            [],
            self::bootKernel()->getContainer(),
            extensionObjects: [
                ...$channels,
                ...$extensionObjects,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [],
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
