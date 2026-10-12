<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\EventSourcing\Database\EventStreamTableManager;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * licence Apache-2.0
 * @internal
 */
final class EventStreamTableInitializationTest extends EventSourcingMessagingTestCase
{
    public function test_the_test_bootstrap_sets_up_the_stream_tables_on_every_engine(): void
    {
        $eventStore = $this->bootstrapEcotone(TestConfiguration::createWithDefaults())->getGateway(EventStore::class);

        $eventStore->appendTo('ecotone_event_stream', [new TicketWasRegistered('123', 'Johnny', 'alert')]);

        self::assertCount(1, $eventStore->load('ecotone_event_stream'));
    }

    public function test_appending_fails_with_the_missing_table_instruction_when_the_bootstrap_does_not_set_up_tables(): void
    {
        $eventStore = $this->bootstrapEcotone(TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false))->getGateway(EventStore::class);

        try {
            $eventStore->appendTo('ecotone_event_stream', [new TicketWasRegistered('123', 'Johnny', 'alert')]);
            self::fail('Expected the missing stream table to be refused');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(MissingTableInstructions::build(EventStreamTableManager::FEATURE_NAME, 'ecotone_event_stream', null), $exception->getMessage());
            self::assertStringContainsString('Ecotone never creates a table while it handles a message', $exception->getMessage());
            self::assertStringContainsString('$ecotone->setupDatabaseTables()', $exception->getMessage());
            self::assertStringContainsString('withDatabaseTablesSetUpOnBootstrap(false)', $exception->getMessage());
        }

        self::assertFalse($eventStore->hasStream('ecotone_event_stream'));
    }

    public function test_setting_up_database_tables_creates_what_the_bootstrap_was_told_to_skip(): void
    {
        $ecotone = $this->bootstrapEcotone(TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false));

        $ecotone->setupDatabaseTables();
        $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new TicketWasRegistered('123', 'Johnny', 'alert')]);

        self::assertCount(1, $ecotone->getGateway(EventStore::class)->load('ecotone_event_stream'));
    }

    public function test_appending_to_a_stream_no_setup_created_is_refused_on_every_engine(): void
    {
        $eventStore = $this->bootstrapEcotone(TestConfiguration::createWithDefaults())->getGateway(EventStore::class);

        try {
            $eventStore->appendTo('stream_nothing_declares', [new TicketWasRegistered('123', 'Johnny', 'alert')]);
            self::fail('Expected a stream table no setup created to be refused');
        } catch (ConfigurationException $exception) {
            self::assertStringStartsWith('Event stream `stream_nothing_declares` is appended to, but nothing declares that stream, so no migration will ever create its table', $exception->getMessage());
            self::assertStringContainsString("Declare it with #[Stream('stream_nothing_declares')]", $exception->getMessage());
        }

        self::assertFalse($eventStore->hasStream('stream_nothing_declares'));
    }

    public function test_appending_after_the_stream_table_is_deleted_raises_the_missing_table_instruction(): void
    {
        $ecotone = $this->bootstrapEcotone(TestConfiguration::createWithDefaults());
        $eventStore = $ecotone->getGateway(EventStore::class);
        $eventStore->appendTo('ecotone_event_stream', [new TicketWasRegistered('123', 'Johnny', 'alert')]);

        $ecotone->getGateway(ConsoleCommandRunner::class)->execute('ecotone:migration:database:delete', ['feature' => [EventStreamTableManager::FEATURE_NAME], 'force' => true]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(EventStreamTableManager::FEATURE_NAME, 'ecotone_event_stream', null));

        $eventStore->appendTo('ecotone_event_stream', [new TicketWasRegistered('124', 'Johnny', 'alert')]);
    }

    private function bootstrapEcotone(TestConfiguration $testConfiguration): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Ticket::class],
            containerOrAvailableServices: [new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults(),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            testConfiguration: $testConfiguration,
        );
    }
}
