<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Scheduling\Duration;
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
final class PulseFeaturesTest extends DbalMessagingTestCase
{
    public function test_database_transactions_by_default_wrap_the_command_bus_asynchronous_endpoints_and_console_commands_except_the_handlers_without_one(): void
    {
        $features = $this->dbalFeatures([]);

        $transactions = $features['Database transactions'];
        self::assertTrue($transactions['enabled']);
        self::assertSame(['Command bus' => 'on', 'Asynchronous endpoints' => 'on', 'Console commands' => 'on', 'Connections' => DbalConnectionFactory::class], $transactions['settings']);
        self::assertSame(['order.cancel → cancel'], $transactions['handlers']);
        self::assertStringContainsString('runs inside one database transaction', $transactions['hint']);
        self::assertStringContainsString('#[WithoutDatabaseTransaction]', $transactions['hint']);
        self::assertSame('DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true)->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnConsoleCommands(true)', $transactions['configuration']);
    }

    public function test_transactions_turned_off_on_an_entry_point_or_an_endpoint_are_reported_where_they_are_off(): void
    {
        $features = $this->dbalFeatures([
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false)->withTransactionOnConsoleCommands(false)->withoutTransactionOnAsynchronousEndpoints(['order.place.endpoint']),
        ]);

        $transactions = $features['Database transactions'];
        self::assertTrue($transactions['enabled']);
        self::assertSame('off', $transactions['settings']['Command bus']);
        self::assertSame('on, except order.place.endpoint', $transactions['settings']['Asynchronous endpoints']);
        self::assertSame('off', $transactions['settings']['Console commands']);
        self::assertSame([], $transactions['handlers']);
        self::assertStringContainsString('A command sent through the command bus does not open a transaction', $transactions['hint']);
    }

    public function test_deduplication_reports_where_handled_messages_are_kept_for_how_long_and_the_deduplicated_handlers(): void
    {
        $features = $this->dbalFeatures([]);

        $deduplication = $features['Deduplication'];
        self::assertTrue($deduplication['enabled']);
        self::assertSame(['Storage' => 'database, connection ' . DbalConnectionFactory::class, 'Kept for' => '7 days', 'Removal batch size' => '1000'], $deduplication['settings']);
        self::assertSame(['order.place → place (by header orderId)'], $deduplication['handlers']);
        self::assertStringContainsString('is skipped instead of being handled twice', $deduplication['hint']);
        self::assertStringContainsString('7 days', $deduplication['hint']);
    }

    public function test_disabled_deduplication_dead_letter_and_document_store_say_what_happens_without_them_and_how_to_enable_them(): void
    {
        $features = $this->dbalFeatures([
            DbalConfiguration::createWithDefaults()->withDeduplication(false)->withDeadLetter(false)->withDocumentStore(false),
        ]);

        self::assertFalse($features['Deduplication']['enabled']);
        self::assertStringContainsString('a redelivered message is handled again', $features['Deduplication']['hint']);
        self::assertSame('DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: true)', $features['Deduplication']['configuration']);
        self::assertFalse($features['Dead letter']['enabled']);
        self::assertStringContainsString('is not stored', $features['Dead letter']['hint']);
        self::assertSame('DbalConfiguration::createWithDefaults()->withDeadLetter(isDeadLetterEnabled: true)', $features['Dead letter']['configuration']);
        self::assertFalse($features['Document store']['enabled']);
    }

    public function test_dead_letter_and_document_store_report_their_storage_when_enabled(): void
    {
        $features = $this->dbalFeatures([]);

        self::assertTrue($features['Dead letter']['enabled']);
        self::assertSame(['Storage' => 'database, connection ' . DbalConnectionFactory::class], $features['Dead letter']['settings']);
        self::assertStringContainsString('Dead letters page', $features['Dead letter']['hint']);
        self::assertTrue($features['Document store']['enabled']);
        self::assertSame('database, connection ' . DbalConnectionFactory::class, $features['Document store']['settings']['Storage']);
    }

    public function test_flow_tracking_reports_its_retention_when_enabled_and_how_to_enable_it_otherwise(): void
    {
        $disabled = $this->dbalFeatures([])['Flow tracking'];
        $enabled = $this->dbalFeatures([FlowTrackingConfiguration::createWithDefaults()->withRetention(Duration::minutes(120))])['Flow tracking'];

        self::assertFalse($disabled['enabled']);
        self::assertSame('FlowTrackingConfiguration::createWithDefaults()', $disabled['configuration']);
        self::assertTrue($enabled['enabled']);
        self::assertSame('2 hours', $enabled['settings']['Kept for']);
        self::assertSame('every flow', $enabled['settings']['Tracked flows']);
        self::assertStringContainsString('Traces page', $enabled['hint']);
    }

    public function test_doctrine_orm_features_are_reported_under_the_dbal_package(): void
    {
        $features = $this->dbalFeatures([]);

        self::assertTrue($features['Doctrine ORM flush and clear']['enabled']);
        self::assertSame(['Command bus' => 'on', 'Asynchronous endpoints' => 'on', 'Projection batches' => 'on'], $features['Doctrine ORM flush and clear']['settings']);
        self::assertFalse($features['Doctrine ORM repositories']['enabled']);
        self::assertSame('DbalConfiguration::createWithDefaults()->withDoctrineORMRepositories(isORMEnabled: true)', $features['Doctrine ORM repositories']['configuration']);
    }

    /**
     * @param object[] $extensionObjects
     * @return array<string, array<string, mixed>>
     */
    private function dbalFeatures(array $extensionObjects): array
    {
        $orders = new class () {
            #[Asynchronous('orders')]
            #[Deduplicated('orderId')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $order): void
            {
            }

            #[WithoutDatabaseTransaction]
            #[CommandHandler('order.cancel')]
            public function cancel(string $order): void
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
        $orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$orders::class],
            containerOrAvailableServices: [$orders, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                ...$extensionObjects,
                DbalBackedMessageChannelBuilder::create('orders'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $pulseService = $this->bootstrapFlowTesting(
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

        return array_column($packages['dbal'], null, 'name');
    }
}
