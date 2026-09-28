<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class DynamicConsistencyBoundaryDisabledDbalTest extends EventSourcingMessagingTestCase
{
    private const DISABLED_MESSAGE = 'Dynamic Consistency Boundary is disabled. Register DynamicConsistencyBoundaryConfiguration::createWithDefaults() as an extension object (#[ServiceContext]) to enable decision models, event tags and append conditions.';

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

    public function test_handler_injecting_a_decision_model_fails_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::DISABLED_MESSAGE);

        $this->bootstrapEcotone([RenamesForDisabledBoundaryDbalTest::class, HandlerInjectingRenamesForDisabledBoundaryDbalTest::class]);
    }

    public function test_aggregate_emitting_tagged_events_is_saved_and_reloaded_without_any_tag_tables(): void
    {
        $ecotone = $this->bootstrapEcotone([TaggedEntityForDisabledBoundaryDbalTest::class]);
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        $ecotone->sendCommand(new CreateTaggedEntityForDisabledBoundaryDbalTest('entity-1'));
        $ecotone->sendCommand(new RenameTaggedEntityForDisabledBoundaryDbalTest('entity-1'));

        self::assertSame(2, $ecotone->getAggregate(TaggedEntityForDisabledBoundaryDbalTest::class, 'entity-1')->version());
    }

    public function test_database_setup_does_not_list_the_event_tags_feature_although_tags_are_declared(): void
    {
        $ecotone = $this->bootstrapEcotone([TaggedEntityForDisabledBoundaryDbalTest::class]);

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', []);

        self::assertNotContains(TagTableManager::FEATURE_NAME, array_column($result->getRows(), 0));
    }

    public function test_loading_by_criteria_throws(): void
    {
        $ecotone = $this->bootstrapEcotone([TaggedEntityForDisabledBoundaryDbalTest::class]);
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::DISABLED_MESSAGE);

        $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('entity', 'entity-1'));
    }

    public function test_backfill_tags_console_command_throws(): void
    {
        $ecotone = $this->bootstrapEcotone([TaggedEntityForDisabledBoundaryDbalTest::class]);
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::DISABLED_MESSAGE);

        $this->executeConsoleCommand($ecotone, 'ecotone:event-store:backfill-tags', []);
    }

    public function test_verify_schema_console_command_throws(): void
    {
        $ecotone = $this->bootstrapEcotone([TaggedEntityForDisabledBoundaryDbalTest::class]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::DISABLED_MESSAGE);

        $this->executeConsoleCommand($ecotone, 'ecotone:event-store:verify-schema', []);
    }

    private function bootstrapEcotone(array $classesToResolve): FlowTestSupport
    {
        return $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [...$classesToResolve, EventsConverterForDisabledBoundaryDbalTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new EventsConverterForDisabledBoundaryDbalTest(), new HandlerInjectingRenamesForDisabledBoundaryDbalTest()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(false),
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function executeConsoleCommand(FlowTestSupport $ecotone, string $commandName, array $parameters): ConsoleCommandResultSet
    {
        /** @var ConsoleCommandRunner $runner */
        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        return $runner->execute($commandName, $parameters);
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

final readonly class CreateTaggedEntityForDisabledBoundaryDbalTest
{
    public function __construct(public string $id)
    {
    }
}

final readonly class RenameTaggedEntityForDisabledBoundaryDbalTest
{
    public function __construct(public string $id)
    {
    }
}

final readonly class RenamedForDisabledBoundaryDbalTest
{
    public function __construct(#[EventTag('entity')] public string $id)
    {
    }
}

#[DecisionModel]
final class RenamesForDisabledBoundaryDbalTest
{
    #[EventSourcingHandler]
    public function renamed(RenamedForDisabledBoundaryDbalTest $event): void
    {
    }
}

final class HandlerInjectingRenamesForDisabledBoundaryDbalTest
{
    #[CommandHandler]
    public function rename(RenameTaggedEntityForDisabledBoundaryDbalTest $command, RenamesForDisabledBoundaryDbalTest $renames): array
    {
        return [new RenamedForDisabledBoundaryDbalTest($command->id)];
    }
}

#[EventSourcingAggregate]
final class TaggedEntityForDisabledBoundaryDbalTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $id;

    #[CommandHandler]
    public static function create(CreateTaggedEntityForDisabledBoundaryDbalTest $command): array
    {
        return [new RenamedForDisabledBoundaryDbalTest($command->id)];
    }

    #[CommandHandler]
    public function rename(RenameTaggedEntityForDisabledBoundaryDbalTest $command): array
    {
        return [new RenamedForDisabledBoundaryDbalTest($this->id)];
    }

    #[EventSourcingHandler]
    public function whenRenamed(RenamedForDisabledBoundaryDbalTest $event): void
    {
        $this->id = $event->id;
    }

    public function version(): int
    {
        return $this->version;
    }
}

final class EventsConverterForDisabledBoundaryDbalTest
{
    #[Converter]
    public function from(RenamedForDisabledBoundaryDbalTest $event): array
    {
        return ['id' => $event->id];
    }

    #[Converter]
    public function to(array $event): RenamedForDisabledBoundaryDbalTest
    {
        return new RenamedForDisabledBoundaryDbalTest($event['id']);
    }
}
