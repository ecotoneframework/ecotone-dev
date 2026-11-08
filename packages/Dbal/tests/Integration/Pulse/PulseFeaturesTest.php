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
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
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

    public function test_with_database_transactions_instant_retries_apply_by_default_to_what_runs_in_a_transaction(): void
    {
        $features = $this->featuresOf([], 'core');

        $asynchronous = $features['Instant retries on asynchronous endpoints'];
        self::assertTrue($asynchronous['enabled']);
        self::assertSame(['Retry times' => '3', 'Retried on' => 'any exception', 'Applied' => 'by default, to endpoints running in a database transaction'], $asynchronous['settings']);
        self::assertStringContainsString('Not configured, so the default applies: a message an asynchronous endpoint consumes in a database transaction is handled once and, when that fails, its transaction is rolled back and it is tried again right away up to 3 more times (4 attempts in total)', $asynchronous['hint']);
        $commandBus = $features['Instant retries on the command bus'];
        self::assertTrue($commandBus['enabled']);
        self::assertSame('by default, to commands handled in a database transaction', $commandBus['settings']['Applied']);
        self::assertStringContainsString('A command whose handler has #[WithoutDatabaseTransaction] is not retried.', $commandBus['hint']);
    }

    public function test_an_asynchronous_endpoint_without_a_transaction_is_named_where_the_default_instant_retries_do_not_apply(): void
    {
        $features = $this->featuresOf([DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false)->withoutTransactionOnAsynchronousEndpoints(['order.place.endpoint'])], 'core');

        self::assertSame('by default, to endpoints running in a database transaction, except order.place.endpoint', $features['Instant retries on asynchronous endpoints']['settings']['Applied']);
        self::assertFalse($features['Instant retries on the command bus']['enabled']);
    }

    public function test_a_failing_command_goes_through_the_instant_retries_reported_for_the_command_bus_and_one_without_a_transaction_does_not(): void
    {
        $orders = new class () {
            public array $attempts = ['order.place' => 0, 'order.cancel' => 0];

            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
                $this->attempts['order.place']++;
                throw new RuntimeException('Payment gateway is down');
            }

            #[WithoutDatabaseTransaction]
            #[CommandHandler('order.cancel')]
            public function cancel(string $order): void
            {
                $this->attempts['order.cancel']++;
                throw new RuntimeException('Payment gateway is down');
            }
        };
        [$orderService, $packages] = $this->reportedBy($orders, []);

        foreach (['order.place', 'order.cancel'] as $routingKey) {
            try {
                $orderService->sendCommandWithRouting($routingKey, 'order-1');
            } catch (RuntimeException) {
            }
        }

        self::assertSame('3', $packages['core']['Instant retries on the command bus']['settings']['Retry times']);
        self::assertSame(['order.cancel → cancel'], $packages['dbal']['Database transactions']['handlers']);
        self::assertSame(['order.place' => 4, 'order.cancel' => 1], $orders->attempts);
    }

    public function test_an_asynchronous_handler_without_a_transaction_is_listed_as_running_without_one_and_is_not_retried(): void
    {
        $orders = new class () {
            public int $attempts = 0;

            #[Asynchronous('orders', asynchronousExecution: [new WithoutDatabaseTransaction()])]
            #[CommandHandler('order.notify', endpointId: 'order.notify.endpoint')]
            public function notify(string $order): void
            {
                $this->attempts++;
                throw new RuntimeException('Mail server is down');
            }
        };
        [$orderService, $packages] = $this->reportedBy($orders, []);

        $orderService->sendCommandWithRouting('order.notify', 'order-1');
        $orderService->run('orders', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        self::assertSame(['order.notify → notify'], $packages['dbal']['Database transactions']['handlers']);
        self::assertStringContainsString('The handlers listed here have #[WithoutDatabaseTransaction] and run without one.', $packages['dbal']['Database transactions']['hint']);
        self::assertSame(1, $orders->attempts);
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
        self::assertSame(['Storage' => 'database, connection ' . DbalConnectionFactory::class, 'Expiration time' => '7 days', 'Removal batch size' => '1000'], $deduplication['settings']);
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
        self::assertSame('2 hours', $enabled['settings']['Retention']);
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
        return $this->featuresOf($extensionObjects, 'dbal');
    }

    /**
     * @param object[] $extensionObjects
     * @return array<string, array<string, mixed>>
     */
    private function featuresOf(array $extensionObjects, string $package): array
    {
        return $this->reportedBy($this->orders(), $extensionObjects)[1][$package];
    }

    private function orders(): object
    {
        return new class () {
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
    }

    /**
     * @param object[] $extensionObjects
     * @return array{0: FlowTestSupport, 1: array<string, array<string, array<string, mixed>>>}
     */
    private function reportedBy(object $orders, array $extensionObjects): array
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

        return [$orderService, array_map(static fn (array $features): array => array_column($features, null, 'name'), $packages)];
    }
}
