<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Integration;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\JMSConverter\JMSConverterConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Messaging\Config\ConfigurationException;
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
    public function test_jms_serialization_reports_its_defaults(): void
    {
        $serialization = $this->features([])['JMS serialization'];

        self::assertTrue($serialization['enabled']);
        self::assertSame(['Naming strategy' => JMSConverterConfiguration::IDENTICAL_PROPERTY_NAMING_STRATEGY, 'Null values' => 'serialized', 'Enums' => 'on'], $serialization['settings']);
        self::assertStringContainsString('property names are kept as they are, null values are serialized and enums are converted to their values', $serialization['hint']);
    }

    public function test_jms_serialization_reports_a_changed_naming_strategy_and_null_handling(): void
    {
        $serialization = $this->features([JMSConverterConfiguration::createWithDefaults()->withNamingStrategy(JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY)->withDefaultNullSerialization(false)])['JMS serialization'];

        self::assertSame(JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY, $serialization['settings']['Naming strategy']);
        self::assertSame('left out', $serialization['settings']['Null values']);
        self::assertStringContainsString('null values are left out', $serialization['hint']);
    }

    public function test_a_service_reporting_to_pulse_still_names_the_way_out_of_two_identical_configurations(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Ecotone was given 2 identical JMSConverterConfigurations');

        $this->features([JMSConverterConfiguration::createWithDefaults(), JMSConverterConfiguration::createWithDefaults()]);
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
            containerOrAvailableServices: [$handlers, ...$services],
            extensionObjects: [
                ...$channels,
                ...$extensionObjects,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::JMS_CONVERTER_PACKAGE],
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

        return array_column($packages['jmsConverter'], null, 'name');
    }
}
