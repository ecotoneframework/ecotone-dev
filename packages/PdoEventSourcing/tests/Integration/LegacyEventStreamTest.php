<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\LegacyStream\CancelLegacyOrder;
use Test\Ecotone\EventSourcing\Fixture\LegacyStream\LegacyOrder;
use Test\Ecotone\EventSourcing\Fixture\LegacyStream\LegacyOrderCancelled;
use Test\Ecotone\EventSourcing\Fixture\LegacyStream\LegacyOrderConverter;
use Test\Ecotone\EventSourcing\Fixture\LegacyStream\LegacyOrderPlaced;
use Test\Ecotone\EventSourcing\Fixture\LegacyStream\PlaceLegacyOrder;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class LegacyEventStreamTest extends EventSourcingMessagingTestCase
{
    public function test_appending_and_reading_through_table_created_by_ecotone_1x(): void
    {
        $connection = $this->getConnection();
        $legacyTable = '_' . sha1(LegacyOrder::LEGACY_STREAM_NAME);
        self::createTableTheOldWay($connection, $legacyTable);

        $ecotone = $this->bootstrap();

        $ecotone->sendCommandWithRouting('legacyOrder.place', new PlaceLegacyOrder('order-1'));

        self::assertSame(
            1,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . self::quote($connection, $legacyTable))
        );
        self::assertFalse(self::tableExists($connection, 'ecotone_event_stream'));

        $ecotone->sendCommandWithRouting('legacyOrder.cancel', new CancelLegacyOrder('order-1'), metadata: ['aggregate.id' => 'order-1']);

        self::assertSame('cancelled', $ecotone->sendQueryWithRouting('legacyOrder.getStatus', metadata: ['aggregate.id' => 'order-1']));
        self::assertSame(
            2,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . self::quote($connection, $legacyTable))
        );
    }

    public function test_reading_events_written_by_ecotone_1x(): void
    {
        $connection = $this->getConnection();
        $legacyTable = '_' . sha1(LegacyOrder::LEGACY_STREAM_NAME);
        self::createTableTheOldWay($connection, $legacyTable);
        self::insertTheOldWay($connection, $legacyTable, 'order-2');

        $ecotone = $this->bootstrap();

        self::assertSame('placed', $ecotone->sendQueryWithRouting('legacyOrder.getStatus', metadata: ['aggregate.id' => 'order-2']));

        $events = $ecotone->getGateway(EventStore::class)->load(LegacyOrder::LEGACY_STREAM_NAME);
        self::assertCount(1, $events);
        self::assertEquals(new LegacyOrderPlaced('order-2'), $events[0]->getPayload());
    }

    public function test_optimistic_concurrency_still_holds_on_legacy_table(): void
    {
        $connection = $this->getConnection();
        $legacyTable = '_' . sha1(LegacyOrder::LEGACY_STREAM_NAME);
        self::createTableTheOldWay($connection, $legacyTable);
        self::insertTheOldWay($connection, $legacyTable, 'order-3');

        $ecotone = $this->bootstrap();

        $this->expectException(\Ecotone\Messaging\Support\ConcurrencyException::class);

        $ecotone->getGateway(EventStore::class)->appendTo(LegacyOrder::LEGACY_STREAM_NAME, [
            \Ecotone\Modelling\Event::createWithType(
                LegacyOrderCancelled::class,
                ['orderId' => 'order-3'],
                [
                    '_aggregate_id' => 'order-3',
                    '_aggregate_type' => LegacyOrder::class,
                    '_aggregate_version' => 1,
                ]
            ),
        ]);
    }

    private function bootstrap(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [LegacyOrder::class, LegacyOrderConverter::class],
            containerOrAvailableServices: [
                new LegacyOrderConverter(),
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );
    }

    private static function quote(Connection $connection, string $tableName): string
    {
        return $connection->getDatabasePlatform()->quoteIdentifier($tableName);
    }

    private static function insertTheOldWay(Connection $connection, string $tableName, string $orderId): void
    {
        $connection->executeStatement(
            'INSERT INTO ' . self::quote($connection, $tableName) . ' (event_id, event_name, payload, metadata, created_at) VALUES (?, ?, ?, ?, ?)',
            [
                '5c8e0e21-ba2e-4a3d-9f2b-0d1c8f3a4b5c',
                LegacyOrderPlaced::class,
                json_encode(['orderId' => $orderId]),
                json_encode([
                    '_aggregate_id' => $orderId,
                    '_aggregate_type' => LegacyOrder::class,
                    '_aggregate_version' => 1,
                ]),
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u'),
            ]
        );
    }

    /**
     * Reproduces the DDL prooph/pdo-event-store emitted for the single/partition
     * persistence strategy, which is what Ecotone 1.x created at runtime.
     */
    private static function createTableTheOldWay(Connection $connection, string $tableName): void
    {
        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement(<<<SQL
                CREATE TABLE "{$tableName}" (
                    no BIGSERIAL,
                    event_id UUID NOT NULL,
                    event_name VARCHAR(100) NOT NULL,
                    payload JSON NOT NULL,
                    metadata JSONB NOT NULL,
                    created_at TIMESTAMP(6) NOT NULL,
                    PRIMARY KEY (no),
                    CONSTRAINT aggregate_version_not_null CHECK ((metadata->>'_aggregate_version') IS NOT NULL),
                    CONSTRAINT aggregate_type_not_null CHECK ((metadata->>'_aggregate_type') IS NOT NULL),
                    CONSTRAINT aggregate_id_not_null CHECK ((metadata->>'_aggregate_id') IS NOT NULL),
                    UNIQUE (event_id)
                )
                SQL);
            $connection->executeStatement(<<<SQL
                CREATE UNIQUE INDEX ON "{$tableName}"
                ((metadata->>'_aggregate_type'), (metadata->>'_aggregate_id'), (metadata->>'_aggregate_version'))
                SQL);
            $connection->executeStatement(<<<SQL
                CREATE INDEX ON "{$tableName}"
                ((metadata->>'_aggregate_type'), (metadata->>'_aggregate_id'), no)
                SQL);

            return;
        }

        $payloadType = $platform instanceof MariaDBPlatform ? 'LONGTEXT' : 'JSON';
        $jsonChecks = $platform instanceof MariaDBPlatform
            ? "CHECK (`payload` IS NOT NULL AND JSON_VALID(`payload`)),\n    CHECK (`metadata` IS NOT NULL AND JSON_VALID(`metadata`)),"
            : '';
        $notNull = $platform instanceof MariaDBPlatform ? '' : 'NOT NULL';

        $connection->executeStatement(<<<SQL
            CREATE TABLE `{$tableName}` (
                `no` BIGINT(20) NOT NULL AUTO_INCREMENT,
                `event_id` CHAR(36) COLLATE utf8mb4_bin NOT NULL,
                `event_name` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
                `payload` {$payloadType} NOT NULL,
                `metadata` {$payloadType} NOT NULL,
                `created_at` DATETIME(6) NOT NULL,
                `aggregate_version` INT(11) UNSIGNED GENERATED ALWAYS AS (JSON_EXTRACT(metadata, '$._aggregate_version')) STORED {$notNull},
                `aggregate_id` CHAR(36) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(metadata, '$._aggregate_id'))) STORED {$notNull},
                `aggregate_type` VARCHAR(150) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(metadata, '$._aggregate_type'))) STORED {$notNull},
                {$jsonChecks}
                PRIMARY KEY (`no`),
                UNIQUE KEY `ix_event_id` (`event_id`),
                UNIQUE KEY `ix_unique_event` (`aggregate_type`, `aggregate_id`, `aggregate_version`),
                KEY `ix_query_aggregate` (`aggregate_type`,`aggregate_id`,`no`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
            SQL);
    }
}
