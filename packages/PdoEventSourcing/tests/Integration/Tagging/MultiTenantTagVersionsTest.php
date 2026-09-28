<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Dbal\EventStreamSchemaFactory;
use Ecotone\EventSourcing\Dbal\Tag\TaggedEventSchemaFactory;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class MultiTenantTagVersionsTest extends EventSourcingMessagingTestCase
{
    public function test_each_tenant_enforces_its_own_issuance_limit_independent_of_the_other_tenant(): void
    {
        $this->initializeStreamAndTagTables($this->connectionForTenantA()->createContext()->getDbalConnection());
        $this->initializeStreamAndTagTables($this->connectionForTenantB()->createContext()->getDbalConnection());

        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommand(new IssueCouponForMultiTenantTest('batch-a1', 'SUMMER24', 2), metadata: ['tenant' => 'tenant_a']);
        $ecotone->sendCommand(new IssueCouponForMultiTenantTest('batch-a2', 'SUMMER24', 2), metadata: ['tenant' => 'tenant_a']);

        $ecotone->sendCommand(new IssueCouponForMultiTenantTest('batch-b1', 'SUMMER24', 2), metadata: ['tenant' => 'tenant_b']);
        $ecotone->sendCommand(new IssueCouponForMultiTenantTest('batch-b2', 'SUMMER24', 2), metadata: ['tenant' => 'tenant_b']);

        $this->expectException(CouponIssuanceLimitReachedForMultiTenantTest::class);
        $ecotone->sendCommand(new IssueCouponForMultiTenantTest('batch-a3', 'SUMMER24', 2), metadata: ['tenant' => 'tenant_a']);
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [CouponForMultiTenantTest::class, CouponIssuerForMultiTenantTest::class, CouponIssuedForMultiTenantTest::class, EventsConverterForMultiTenantTest::class],
            containerOrAvailableServices: [
                new EventsConverterForMultiTenantTest(),
                new CouponIssuerForMultiTenantTest(),
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createForTesting()->withTransactionOnCommandBus(true),
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

    private function initializeStreamAndTagTables(\Doctrine\DBAL\Connection $connection): void
    {
        foreach (EventStreamSchemaFactory::for($connection)->createTableSql('ecotone_event_stream') as $statement) {
            $connection->executeStatement($statement);
        }

        $tagSchema = TaggedEventSchemaFactory::for($connection);
        foreach ([...$tagSchema->createTaggedEventsTableSql('ecotone_tagged_events'), ...$tagSchema->createTagVersionsTableSql('ecotone_tag_versions')] as $statement) {
            $connection->executeStatement($statement);
        }
    }
}

final readonly class IssueCouponForMultiTenantTest
{
    public function __construct(
        public string $batchId,
        #[EventTag('coupon')] public string $code,
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

/**
 * @internal
 */
final class CouponIssuanceLimitReachedForMultiTenantTest extends RuntimeException
{
}

#[DecisionModel]
final class CouponForMultiTenantTest
{
    private int $issued = 0;

    #[EventSourcingHandler]
    public function whenIssued(CouponIssuedForMultiTenantTest $event): void
    {
        $this->issued++;
    }

    public function issuedCount(): int
    {
        return $this->issued;
    }
}

final class CouponIssuerForMultiTenantTest
{
    #[CommandHandler]
    public function issue(IssueCouponForMultiTenantTest $command, CouponForMultiTenantTest $coupon): array
    {
        if ($coupon->issuedCount() >= $command->limit) {
            throw new CouponIssuanceLimitReachedForMultiTenantTest();
        }

        return [new CouponIssuedForMultiTenantTest($command->batchId, $command->code, $command->limit)];
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
