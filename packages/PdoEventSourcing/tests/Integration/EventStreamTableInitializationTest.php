<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\EventSourcing\Database\EventStreamTableManager;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class EventStreamTableInitializationTest extends EventSourcingMessagingTestCase
{
    public function test_create_fails_when_auto_initialization_disabled_and_table_not_created(): void
    {
        $eventStore = $this->bootstrapWithAutomaticTableInitialization(false)->getGateway(EventStore::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(EventStreamTableManager::FEATURE_NAME);

        $eventStore->create(Uuid::v7()->toRfc4122());
    }

    public function test_append_to_fails_when_auto_initialization_disabled_and_table_not_created(): void
    {
        $eventStore = $this->bootstrapWithAutomaticTableInitialization(false)->getGateway(EventStore::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(EventStreamTableManager::FEATURE_NAME);

        $eventStore->appendTo(Uuid::v7()->toRfc4122(), [new TicketWasRegistered('123', 'Johnny', 'alert')]);
    }

    public function test_append_to_after_the_stream_table_is_deleted_in_the_same_process_raises_the_missing_table_instruction(): void
    {
        $ecotone = $this->bootstrapWithAutomaticTableInitialization(false);
        $ecotone->initializeDatabase();
        $eventStore = $ecotone->getGateway(EventStore::class);
        $eventStore->appendTo('ecotone_event_stream', [new TicketWasRegistered('123', 'Johnny', 'alert')]);

        $ecotone->getGateway(ConsoleCommandRunner::class)->execute('ecotone:migration:database:delete', ['feature' => [EventStreamTableManager::FEATURE_NAME], 'force' => true]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(EventStreamTableManager::FEATURE_NAME, 'ecotone_event_stream', null));

        $eventStore->appendTo('ecotone_event_stream', [new TicketWasRegistered('124', 'Johnny', 'alert')]);
    }

    private function bootstrapWithAutomaticTableInitialization(bool $isEnabled): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Ticket::class],
            containerOrAvailableServices: [new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization($isEnabled),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );
    }
}
