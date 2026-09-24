<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class MultiTenantTaggedEventStoreTest extends EventSourcingMessagingTestCase
{
    private DbalConnectionFactory $tenantBConnectionFactory;

    public function setUp(): void
    {
        parent::setUp();

        // Tenant B is deliberately a throwaway SQLite file, never the engine DATABASE_DSN points to for this run
        // (which is what tenant A uses): a "second tenant" whose connection env var happens to resolve to the
        // exact same physical database as the first must not look like isolation working by accident.
        $this->tenantBConnectionFactory = new DbalConnectionFactory(
            'sqlite:///' . sys_get_temp_dir() . '/ecotone_multi_tenant_test_tenant_b_' . uniqid('', true) . '.db'
        );

        self::clearDataTables($this->connectionForTenantA()->createContext()->getDbalConnection());
    }

    public function test_each_tenant_has_its_own_tag_counters(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommand(new IssueCouponForMultiTenantTest('batch-a1', 'SUMMER24', 2), metadata: ['tenant' => 'tenant_a']);
        $ecotone->sendCommand(new IssueCouponForMultiTenantTest('batch-a2', 'SUMMER24', 2), metadata: ['tenant' => 'tenant_a']);
        $ecotone->sendCommand(new IssueCouponForMultiTenantTest('batch-b1', 'SUMMER24', 5), metadata: ['tenant' => 'tenant_b']);

        $connectionA = $this->connectionForTenantA()->createContext()->getDbalConnection();
        $connectionB = $this->tenantBConnectionFactory->createContext()->getDbalConnection();

        $versionA = (int) $connectionA->executeQuery(
            "SELECT version FROM ecotone_tag_versions WHERE tag_name = 'coupon' AND tag_value = 'SUMMER24'"
        )->fetchOne();
        $versionB = (int) $connectionB->executeQuery(
            "SELECT version FROM ecotone_tag_versions WHERE tag_name = 'coupon' AND tag_value = 'SUMMER24'"
        )->fetchOne();

        self::assertSame(2, $versionA, "Tenant A issued twice -- its own counter must read 2");
        self::assertSame(1, $versionB, "Tenant B issued once -- its own counter must read 1, unaffected by tenant A's two writes");
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [CouponForMultiTenantTest::class, CouponIssuedForMultiTenantTest::class, EventsConverterForMultiTenantTest::class],
            containerOrAvailableServices: [
                new EventsConverterForMultiTenantTest(),
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->tenantBConnectionFactory,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    MultiTenantConfiguration::create(
                        'tenant',
                        [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ]
                    ),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

final readonly class IssueCouponForMultiTenantTest
{
    public function __construct(
        public string $batchId,
        public string $code,
        public int $limit,
    ) {
    }
}

final readonly class CouponIssuedForMultiTenantTest
{
    public function __construct(
        public string $batchId,
        #[EventTag('coupon')] public string $code,
        public int $limit,
    ) {
    }
}

#[EventSourcingAggregate]
final class CouponForMultiTenantTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $batchId;

    #[CommandHandler]
    public static function issue(IssueCouponForMultiTenantTest $command): array
    {
        return [new CouponIssuedForMultiTenantTest($command->batchId, $command->code, $command->limit)];
    }

    #[EventSourcingHandler]
    public function whenIssued(CouponIssuedForMultiTenantTest $event): void
    {
        $this->batchId = $event->batchId;
    }
}

final class EventsConverterForMultiTenantTest
{
    #[Converter]
    public function from(CouponIssuedForMultiTenantTest $event): array
    {
        return ['batchId' => $event->batchId, 'code' => $event->code, 'limit' => $event->limit];
    }

    #[Converter]
    public function to(array $event): CouponIssuedForMultiTenantTest
    {
        return new CouponIssuedForMultiTenantTest($event['batchId'], $event['code'], $event['limit']);
    }
}
