<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Support\LicensingException;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class TagOperationsWithoutEnterpriseLicenceDbalTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTagTables();
    }

    public function tearDown(): void
    {
        $this->dropTagTables();
        parent::tearDown();
    }

    public function test_untagged_append_works_end_to_end_without_a_licence(): void
    {
        $eventStore = $this->bootstrapEcotoneWithoutLicence()->getGateway(EventStore::class);

        $eventStore->appendTo(self::STREAM, [new UntaggedOrderPlacedForOpenCoreCollaboratorTest('o-1')]);

        self::assertCount(1, $eventStore->load(self::STREAM));
    }

    public function test_loading_events_by_tag_criteria_without_a_licence_throws(): void
    {
        $eventStore = $this->bootstrapEcotoneWithoutLicence()->getGateway(EventStore::class);

        $this->expectException(LicensingException::class);

        $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'));
    }

    private function bootstrapEcotoneWithoutLicence()
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [UntaggedOrderPlacedForOpenCoreCollaboratorTest::class, EventsConverterForOpenCoreCollaboratorTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new EventsConverterForOpenCoreCollaboratorTest()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true),
                ]),
            runForProductionEventStore: true,
        );
        $ecotone->initializeDatabase();

        return $ecotone;
    }

    private function dropTagTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
        if (self::tableExists($connection, self::STREAM)) {
            $connection->executeStatement('DROP TABLE ' . self::STREAM);
        }
    }
}

final readonly class UntaggedOrderPlacedForOpenCoreCollaboratorTest
{
    public function __construct(
        public string $orderId,
    ) {
    }
}

final class EventsConverterForOpenCoreCollaboratorTest
{
    #[Converter]
    public function fromUntaggedOrderPlaced(UntaggedOrderPlacedForOpenCoreCollaboratorTest $event): array
    {
        return ['orderId' => $event->orderId];
    }

    #[Converter]
    public function toUntaggedOrderPlaced(array $event): UntaggedOrderPlacedForOpenCoreCollaboratorTest
    {
        return new UntaggedOrderPlacedForOpenCoreCollaboratorTest($event['orderId']);
    }
}