<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\DestinationDoesNotExistException;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use Symfony\Component\Uid\Uuid;
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
final class PulseReplyChannelTest extends ConnectionTestCase
{
    public function test_the_reply_channel_uses_the_connection_of_the_request_channel_and_its_own_destination(): void
    {
        $replyChannel = 'pulse_' . Uuid::v7()->toRfc4122();
        $pulse = new class () {
            public array $snapshots = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }
        };
        $services = ['pulse_connection' => $this->getConnectionFactory()];
        $orderService = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: $services,
            extensionObjects: [
                SqsBackedMessageChannelBuilder::create('order_service', 'pulse_connection'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults($replyChannel)->withReplyChannel($replyChannel),
            ],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $pulseService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$pulse::class],
            containerOrAvailableServices: [...$services, $pulse],
            extensionObjects: [
                SqsBackedMessageChannelBuilder::create('order_service', 'pulse_connection'),
                SqsBackedMessageChannelBuilder::create($replyChannel, 'pulse_connection'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: $replyChannel, channelName: $replyChannel)->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_service'),
                ServiceConfiguration::createWithDefaults()->withServiceName($replyChannel),
            ],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $pulseService->run($replyChannel, ExecutionPollingMetadata::createWithTestingSetup());
        $pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $orderService->run('order_service', ExecutionPollingMetadata::createWithTestingSetup());
        $pulseService->run($replyChannel, ExecutionPollingMetadata::createWithTestingSetup());

        self::assertCount(1, $pulse->snapshots);
    }

    public function test_a_service_replying_while_the_reply_queue_of_pulse_does_not_exist_fails_and_replies_once_pulse_has_declared_it(): void
    {
        $replyChannel = 'pulse_' . Uuid::v7()->toRfc4122();
        $pulse = new class () {
            public array $snapshots = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }
        };
        $services = ['pulse_connection' => $this->getConnectionFactory()];
        $orderService = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: $services,
            extensionObjects: [
                SqsBackedMessageChannelBuilder::create('order_service', 'pulse_connection'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults($replyChannel)->withReplyChannel($replyChannel),
            ],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $requester = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: $services,
            extensionObjects: [
                SqsBackedMessageChannelBuilder::create('order_service', 'pulse_connection'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_service'),
                ServiceConfiguration::createWithDefaults()->withServiceName($replyChannel),
            ],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $requester->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');

        try {
            $orderService->run('order_service', ExecutionPollingMetadata::createWithTestingSetup());
            self::fail('The reply should fail while the reply queue of Pulse does not exist');
        } catch (DestinationDoesNotExistException $exception) {
            self::assertStringContainsString(sprintf("queue '%s' does not exist", $replyChannel), $exception->getMessage());
        }

        $pulseService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$pulse::class],
            containerOrAvailableServices: [...$services, $pulse],
            extensionObjects: [
                SqsBackedMessageChannelBuilder::create('order_service', 'pulse_connection'),
                SqsBackedMessageChannelBuilder::create($replyChannel, 'pulse_connection'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: $replyChannel, channelName: $replyChannel)->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_service'),
                ServiceConfiguration::createWithDefaults()->withServiceName($replyChannel),
            ],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $pulseService->run($replyChannel, ExecutionPollingMetadata::createWithTestingSetup());
        $orderService->run('order_service', ExecutionPollingMetadata::createWithTestingSetup());
        $pulseService->run($replyChannel, ExecutionPollingMetadata::createWithTestingSetup());

        self::assertCount(1, $pulse->snapshots);
    }
}
