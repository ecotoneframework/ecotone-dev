<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

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
final class PulseReplyChannelFromRequestChannelTest extends DbalMessagingTestCase
{
    public function test_the_reply_follows_the_transport_of_the_request_channel(): void
    {
        $pulse = $this->pulseReplies();

        $this->assertSnapshotReachesPulse($pulse, $this->orderService([DbalBackedMessageChannelBuilder::create('order_service')], PulseConfiguration::createWithDefaults('ecotone_pulse')), $this->pulseService($pulse), 'ecotone_pulse');
    }

    public function test_the_reply_follows_the_request_channel_chosen_with_with_request_channel(): void
    {
        $pulse = $this->pulseReplies();

        $this->assertSnapshotReachesPulse($pulse, $this->orderService([DbalBackedMessageChannelBuilder::create('pulse_requests')], PulseConfiguration::createWithDefaults('ecotone_pulse')->withRequestChannel('pulse_requests')), $this->pulseService($pulse, 'pulse_requests'), 'ecotone_pulse', 'pulse_requests');
    }

    public function test_an_explicitly_registered_reply_channel_is_used_instead_of_a_derived_one(): void
    {
        $pulse = $this->pulseReplies();

        $this->assertSnapshotReachesPulse($pulse, $this->orderService([DbalBackedMessageChannelBuilder::create('order_service'), DbalBackedMessageChannelBuilder::create('ecotone_pulse')], PulseConfiguration::createWithDefaults('ecotone_pulse')), $this->pulseService($pulse), 'ecotone_pulse');
    }

    public function test_a_service_without_its_request_channel_cannot_derive_the_reply_channel(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("the request channel 'order_service' is not registered either");

        $this->orderService([], PulseConfiguration::createWithDefaults('ecotone_pulse'));
    }

    private function assertSnapshotReachesPulse(object $pulse, FlowTestSupport $orderService, FlowTestSupport $pulseService, string $replyChannel, string $requestChannel = 'order_service'): void
    {
        $pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $orderService->run($requestChannel, ExecutionPollingMetadata::createWithTestingSetup());
        $pulseService->run($replyChannel, ExecutionPollingMetadata::createWithTestingSetup());

        self::assertCount(1, $pulse->snapshots);
        self::assertSame('order_service', json_decode($pulse->snapshots[0], true, flags: JSON_THROW_ON_ERROR)['serviceName']);
    }

    private function orderService(array $channels, PulseConfiguration $pulseConfiguration): FlowTestSupport
    {
        $orderService = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                $pulseConfiguration,
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $orderService->setupDatabaseTables();

        return $orderService;
    }

    private function pulseService(object $pulse, string $requestChannel = 'order_service'): FlowTestSupport
    {
        $pulseService = $this->bootstrapFlowTesting(
            classesToResolve: [$pulse::class],
            containerOrAvailableServices: [$pulse, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create($requestChannel),
                DbalBackedMessageChannelBuilder::create('ecotone_pulse'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: $requestChannel),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $pulseService->setupDatabaseTables();

        return $pulseService;
    }

    private function pulseReplies(): object
    {
        return new class () {
            public array $snapshots = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }
        };
    }
}
