<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Result;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
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
final class PulseSetupStatusTest extends DbalMessagingTestCase
{
    private object $pulse;
    private object $orders;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;

    public function test_the_snapshot_reports_every_used_table_as_set_up(): void
    {
        $this->bootstrap();

        $tables = $this->tablesByFeature($this->requestSnapshot());

        self::assertTrue($tables['message_queue']['initialized']);
        self::assertSame(DbalConnectionFactory::class, $tables['message_queue']['connection']);
    }

    public function test_a_missing_table_is_reported_as_not_set_up_and_the_snapshot_still_arrives(): void
    {
        $this->bootstrap();
        $this->orderService->getServiceFromContainer(DatabaseSetupManager::class)->drop('message_queue');

        $snapshot = $this->requestSnapshot();

        self::assertFalse($this->tablesByFeature($snapshot)['message_queue']['initialized']);
        self::assertSame('order_service', $snapshot['serviceName']);
        $this->orderService->getServiceFromContainer(DatabaseSetupManager::class)->initialize('message_queue');
        self::assertTrue($this->tablesByFeature($this->requestSnapshot())['message_queue']['initialized']);
    }

    public function test_each_channel_names_the_consumer_to_run_and_the_handlers_waiting_on_it(): void
    {
        $this->bootstrap();

        $channels = array_column($this->requestSnapshot()['channels'], null, 'name');

        self::assertSame('orders', $channels['orders']['consumerToRun']);
        self::assertSame(['order.place → place'], $channels['orders']['handlers']);
        self::assertSame('order_channel', $channels['order_channel']['consumerToRun']);
        self::assertSame([], $channels['order_channel']['handlers']);
    }

    public function test_a_dbal_channel_leaves_the_channel_setup_unknown_because_its_table_is_a_database_table(): void
    {
        $this->bootstrap();

        $channels = array_column($this->requestSnapshot()['channels'], null, 'name');

        self::assertArrayHasKey('setUp', $channels['orders']);
        self::assertNull($channels['orders']['setUp']);
    }

    public function test_a_slow_schema_read_does_not_delay_the_snapshot_past_the_budget_and_the_other_sections_still_arrive(): void
    {
        $platform = $this->getConnection()->getDatabasePlatform();
        if (! $platform instanceof PostgreSQLPlatform && ! $platform instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('A schema read can only stall on a database server; SQLite reads a local file, so the setup budget is left unproved on SQLite.');
        }
        SlowSchemaReadConnection::$secondsPerSchemaRead = 0.0;
        $this->bootstrap(new DbalConnectionFactory(['dsn' => getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone', 'connection' => ['wrapperClass' => SlowSchemaReadConnection::class]]));
        SlowSchemaReadConnection::$secondsPerSchemaRead = 1.5;

        $startedAt = hrtime(true);
        $snapshot = $this->requestSnapshot();
        $tookMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
        SlowSchemaReadConnection::$secondsPerSchemaRead = 0.0;

        self::assertLessThan(4000, $tookMilliseconds);
        self::assertSame('order_service', $snapshot['serviceName']);
        self::assertContains('orders', array_column($snapshot['channels'], 'name'));
        $unchecked = array_filter($snapshot['setup']['tables'], static fn (array $table): bool => $table['initialized'] === null);
        self::assertNotSame([], $unchecked);
        self::assertSame(['not checked within the budget'], array_values(array_unique(array_column($unchecked, 'error'))));
        self::assertArrayNotHasKey('sectionErrors', $snapshot);
    }

    private function bootstrap(?DbalConnectionFactory $connectionFactory = null): void
    {
        $this->orders = new class () {
            #[Asynchronous('orders')]
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
            classesToResolve: [$this->orders::class],
            containerOrAvailableServices: [$this->orders, DbalConnectionFactory::class => $connectionFactory ?? $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                DbalBackedMessageChannelBuilder::create('orders'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC'))),
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

    private function tablesByFeature(array $snapshot): array
    {
        return array_column($snapshot['setup']['tables'], null, 'feature');
    }
}

final class SlowSchemaReadConnection extends Connection
{
    public static float $secondsPerSchemaRead = 0.0;

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        if (self::$secondsPerSchemaRead > 0.0 && stripos($sql, 'table_name') !== false) {
            parent::executeQuery($this->getDatabasePlatform() instanceof PostgreSQLPlatform ? sprintf('SELECT pg_sleep(%s)', self::$secondsPerSchemaRead) : sprintf('SELECT SLEEP(%s)', self::$secondsPerSchemaRead))->free();
        }

        return parent::executeQuery($sql, $params, $types, $qcp);
    }
}
