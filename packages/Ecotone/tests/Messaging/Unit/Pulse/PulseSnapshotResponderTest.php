<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Pulse;

use DateTimeImmutable;
use DateTimeZone;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\LicensingException;
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
final class PulseSnapshotResponderTest extends TestCase
{
    public function test_the_snapshot_reply_describes_the_service_in_one_json_document(): void
    {
        $orders = $this->orders();
        $pulse = $this->pulseReplies();
        $channels = $this->channels();
        $orderService = $this->orderService($orders, $channels);
        $pulseService = $this->pulseService($pulse, $channels);

        $pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertCount(1, $pulse->snapshots);
        $snapshot = json_decode($pulse->snapshots[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $snapshot['schemaVersion']);
        self::assertSame('order_service', $snapshot['serviceName']);
        self::assertSame('2026-10-20T12:00:00+00:00', $snapshot['takenAt']);
        self::assertSame(PHP_VERSION, $snapshot['overview']['phpVersion']);
        self::assertSame('lite', $snapshot['overview']['framework']);
        self::assertSame(['enterprise' => true, 'expiresAt' => null], $snapshot['overview']['licence']);
        self::assertIsString($snapshot['overview']['ecotoneVersion']);
        self::assertNotSame('', $snapshot['overview']['ecotoneVersion']);
        self::assertArrayHasKey('nodes', $snapshot['flowGraph']);
        self::assertArrayHasKey('edges', $snapshot['flowGraph']);
        self::assertContains('order.place', array_column($snapshot['flowGraph']['nodes'], 'label'));
        self::assertSame(
            [[
                'referenceName' => 'Ecotone\Api\Gateway\DistributedBus',
                'commandMapping' => ['ecotone_pulse' => 'pulse_channel'],
                'eventSubscriptions' => [],
                'asynchronousChannel' => null,
            ]],
            $snapshot['topology']['serviceMaps'],
        );
    }

    public function test_an_asynchronous_service_map_is_reported_in_the_topology(): void
    {
        $channels = $this->channels();
        $orderService = $this->orderService($this->orders(), $channels, DistributedServiceMap::initialize()
            ->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel')
            ->withEventMapping(channelName: 'order_channel', subscriptionKeys: ['order.*'], excludePublishingServices: ['legacy'])
            ->withEventMapping(channelName: 'audit_channel', subscriptionKeys: ['audit.*'], includePublishingServices: ['billing'])
            ->withAsynchronousChannel('order_channel'));
        $pulse = $this->pulseReplies();
        $pulseService = $this->pulseService($pulse, $channels);

        $pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $snapshot = json_decode($pulse->snapshots[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            [
                ['channel' => 'order_channel', 'subscriptionKeys' => ['order.*'], 'excludePublishingServices' => ['legacy'], 'includePublishingServices' => []],
                ['channel' => 'audit_channel', 'subscriptionKeys' => ['audit.*'], 'excludePublishingServices' => [], 'includePublishingServices' => ['billing']],
            ],
            $snapshot['topology']['serviceMaps'][0]['eventSubscriptions'],
        );
        self::assertSame('order_channel', $snapshot['topology']['serviceMaps'][0]['asynchronousChannel']);
    }

    public function test_a_request_from_a_service_other_than_the_pulse_service_is_refused(): void
    {
        $channels = [...$this->channels(), SimpleMessageChannelBuilder::createQueueChannel('intruder_channel')];
        $orderService = $this->orderService($this->orders(), $channels);
        $intruder = EcotoneLite::bootstrapFlowTesting(
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('intruder'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $intruder->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Service order_service was asked for its Pulse snapshot by intruder, but it only answers the configured Pulse service ecotone_pulse. Send the request from the Pulse service, or change the accepted service with PulseConfiguration::createWithDefaults(pulseServiceName: 'intruder') in a #[ServiceContext].");
        $orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_a_responder_with_an_asynchronous_distributed_bus_still_replies_to_the_pulse_service(): void
    {
        $channels = [...$this->channels(), SimpleMessageChannelBuilder::createQueueChannel('order_bus')];
        $orderService = $this->orderService($this->orders(), $channels, $this->pulseMap()->withAsynchronousChannel('order_bus'));
        $pulse = $this->pulseReplies();
        $pulseService = $this->pulseService($pulse, $channels);

        $pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertCount(1, $pulse->snapshots);
        self::assertSame('order_bus', json_decode($pulse->snapshots[0], true, flags: JSON_THROW_ON_ERROR)['topology']['serviceMaps'][0]['asynchronousChannel']);
    }

    public function test_a_request_without_a_source_service_is_refused(): void
    {
        $channels = $this->channels();
        $orderService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$this->orders()::class, SourcelessPulseRequest::class],
            containerOrAvailableServices: [$this->orders()],
            extensionObjects: [
                ...$channels,
                $this->pulseMap(),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('was asked for its Pulse snapshot by no service');
        $orderService->getGateway(SourcelessPulseRequest::class)->ask('{}');
    }

    public function test_pulse_without_an_enterprise_licence_is_refused_at_bootstrap(): void
    {
        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('Ecotone Pulse is available only as part of Ecotone Enterprise. Provide a licence key, or remove the PulseConfiguration from your #[ServiceContext]. See https://docs.ecotone.tech/enterprise for details.');

        EcotoneLite::bootstrapFlowTesting(
            extensionObjects: [...$this->channels(), PulseConfiguration::createWithDefaults('ecotone_pulse'), ServiceConfiguration::createWithDefaults()->withServiceName('order_service')],
            enabledModules: [],
        );
    }

    public function test_pulse_without_a_service_name_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("PulseConfiguration needs this service to be named, so Pulse can tell which service answered. Set it with ServiceConfiguration::createWithDefaults()->withServiceName('my_service') in a #[ServiceContext].");

        EcotoneLite::bootstrapFlowTesting(
            extensionObjects: [...$this->channels(), PulseConfiguration::createWithDefaults('ecotone_pulse')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_pulse_without_a_service_map_to_the_pulse_service_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("PulseConfiguration answers Pulse service ecotone_pulse, but no DistributedServiceMap maps it to a channel, so the reply could not be routed. Add DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'channel_pulse_consumes') to a #[ServiceContext], or change the Pulse service name in PulseConfiguration::createWithDefaults().");

        EcotoneLite::bootstrapFlowTesting(
            extensionObjects: [
                ...$this->channels(),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'other_service', channelName: 'order_channel'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_pulse_configured_twice_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('PulseConfiguration was registered 2 times, but a service answers to exactly one Pulse service. Keep a single #[ServiceContext] returning PulseConfiguration::createWithDefaults(pulseServiceName: ...) and remove the others.');

        EcotoneLite::bootstrapFlowTesting(
            extensionObjects: [
                ...$this->channels(),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
                PulseConfiguration::createWithDefaults('another_pulse'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                $this->pulseMap(),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function pulseMap(): DistributedServiceMap
    {
        return DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel');
    }

    private function orders(): object
    {
        return new class () {
            #[CommandHandler('order.place')]
            public function place(string $orderId): void
            {
            }
        };
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

    private function channels(): array
    {
        return [SimpleMessageChannelBuilder::createQueueChannel('order_channel'), SimpleMessageChannelBuilder::createQueueChannel('pulse_channel')];
    }

    private function orderService(object $handlers, array $channels, ?DistributedServiceMap $serviceMap = null): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handlers::class],
            containerOrAvailableServices: [$handlers],
            extensionObjects: [
                ...$channels,
                $serviceMap ?? $this->pulseMap(),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC'))),
        );
    }

    private function pulseService(object $handlers, array $channels): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handlers::class],
            containerOrAvailableServices: [$handlers],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

interface SourcelessPulseRequest
{
    #[MessageGateway('ecotone.pulse.snapshot')]
    public function ask(#[Payload] string $payload): string;
}
