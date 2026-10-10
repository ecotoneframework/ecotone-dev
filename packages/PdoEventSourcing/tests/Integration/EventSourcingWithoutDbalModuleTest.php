<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * licence Apache-2.0
 * @internal
 */
final class EventSourcingWithoutDbalModuleTest extends EventSourcingMessagingTestCase
{
    public function test_a_database_event_store_without_the_dbal_module_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Event sourcing is configured to store events in the database, but the DBAL module is not enabled. The event stream, projection state and tag tables belong to the DBAL module: without it nothing can create them (ecotone:migration:database:setup and \$ecotone->setupDatabaseTables() are the DBAL module's), so every append and every projection would fail on a missing table. Enable it by adding ModulePackageList::DBAL_PACKAGE to the module packages - ServiceConfiguration::withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ...]), or 'dbal' in the modulePackages option of the Symfony bundle, config/ecotone.php in Laravel or EcotoneConfig in Tempest. To keep the events in memory instead, register EventSourcingConfiguration::createInMemory().");

        EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [new TicketEventConverter(), DbalConnectionFactory::class => self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE]),
            runForProductionEventStore: true,
        );
    }

    public function test_an_in_memory_event_store_needs_no_dbal_module(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [Ticket::class, TicketEventConverter::class],
            [new TicketEventConverter()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([EventSourcingConfiguration::createInMemory()]),
        );

        $ecotone->sendCommand(new RegisterTicket('1', 'Johnny', 'alert'));

        self::assertSame('Johnny', $ecotone->sendQueryWithRouting('ticket.getAssignedPerson', metadata: ['aggregate.id' => '1']));
    }
}
