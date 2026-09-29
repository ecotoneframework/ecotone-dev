<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use function array_map;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\Event;
use Ecotone\Test\LicenceTesting;

use function sys_get_temp_dir;

use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

use function uniqid;

/**
 * licence Enterprise
 * @internal
 */
final class LiteralEventTagDecisionModelDbalTest extends EventSourcingMessagingTestCase
{
    private const CLASSES = [
        InvoiceIssuingHandlerForDbalLiteralTag::class,
        InvoiceNumberingForDbalLiteralTag::class,
        InvoiceIssuedForDbalLiteralTag::class,
        InvoiceConverterForDbalLiteralTag::class,
    ];

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
    }

    public function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function test_gapless_numbering_over_a_class_level_literal_tag_needs_no_value_on_the_command(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommand(new IssueInvoiceForDbalLiteralTag('customer-1'));
        $ecotone->sendCommand(new IssueInvoiceForDbalLiteralTag('customer-2'));
        $ecotone->sendCommand(new IssueInvoiceForDbalLiteralTag('customer-1'));

        self::assertSame([1, 2, 3], $this->issuedNumbers($ecotone));
    }

    /**
     * @return int[]
     */
    private function issuedNumbers(FlowTestSupport $ecotone): array
    {
        return array_map(
            static fn (Event $event): int => $event->getPayload()->number,
            $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('invoiceSequence', 'default'))->events,
        );
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: self::CLASSES,
            containerOrAvailableServices: [self::getConnectionFactory(), new InvoiceIssuingHandlerForDbalLiteralTag(), new InvoiceConverterForDbalLiteralTag()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DynamicConsistencyBoundaryConfiguration::createWithDefaults(),
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->initializeDatabase();

        return $ecotone;
    }

    private function dropTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE, 'ecotone_event_stream'] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

/**
 * @internal
 */
final readonly class IssueInvoiceForDbalLiteralTag
{
    public function __construct(public string $customerId)
    {
    }
}

/**
 * @internal
 */
#[EventTag('invoiceSequence', value: 'default')]
final readonly class InvoiceIssuedForDbalLiteralTag
{
    public function __construct(public int $number)
    {
    }
}

/**
 * @internal
 */
#[DecisionModel(tags: ['invoiceSequence'])]
final class InvoiceNumberingForDbalLiteralTag
{
    private int $lastNumber = 0;

    #[EventSourcingHandler]
    public function issued(InvoiceIssuedForDbalLiteralTag $event): void
    {
        $this->lastNumber = $event->number;
    }

    public function nextNumber(): int
    {
        return $this->lastNumber + 1;
    }
}

/**
 * @internal
 */
final class InvoiceIssuingHandlerForDbalLiteralTag
{
    #[CommandHandler]
    public function issue(IssueInvoiceForDbalLiteralTag $command, InvoiceNumberingForDbalLiteralTag $numbering): array
    {
        return [new InvoiceIssuedForDbalLiteralTag($numbering->nextNumber())];
    }
}

/**
 * @internal
 */
final class InvoiceConverterForDbalLiteralTag
{
    #[Converter]
    public function fromInvoiceIssued(InvoiceIssuedForDbalLiteralTag $event): array
    {
        return ['number' => $event->number];
    }

    #[Converter]
    public function toInvoiceIssued(array $event): InvoiceIssuedForDbalLiteralTag
    {
        return new InvoiceIssuedForDbalLiteralTag($event['number']);
    }
}
