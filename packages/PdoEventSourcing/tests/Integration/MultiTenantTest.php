<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjectionMultiTenant\InProgressTicketList;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class MultiTenantTest extends EventSourcingMessagingTestCase
{
    public function test_building_asynchronous_event_driven_projection_with_multi_tenancy(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [
                new InProgressTicketList(), new TicketEventConverter(),
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
                'logger' => new EchoLogger(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjectionMultiTenant',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    MultiTenantConfiguration::create(
                        'tenant',
                        [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ]
                    ),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->sendCommand(
            new RegisterTicket('123', 'Johnny', 'alert'),
            metadata: ['tenant' => 'tenant_a']
        );
        $ecotone->sendCommand(
            new RegisterTicket('122', 'Johnny', 'alert'),
            metadata: ['tenant' => 'tenant_b']
        );
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertEquals(
            [['ticket_id' => '123', 'ticket_type' => 'alert']],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_a'])
        );
        self::assertEquals(
            [['ticket_id' => '122', 'ticket_type' => 'alert']],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_b'])
        );

        $ecotone->sendCommand(
            new RegisterTicket('124', 'Johnny', 'alert'),
            metadata: ['tenant' => 'tenant_b']
        );
        $ecotone->sendCommand(
            new CloseTicket('123'),
            metadata: ['tenant' => 'tenant_a']
        );

        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertEquals(
            [],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_a'])
        );
        self::assertEquals(
            [
                ['ticket_id' => '122', 'ticket_type' => 'alert'],
                ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_b'])
        );
    }

    public function test_building_synchronous_event_driven_projection_with_multi_tenancy(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [
                new \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjectionMultiTenant\InProgressTicketList(), new TicketEventConverter(),
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
                'logger' => new EchoLogger(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjectionMultiTenant',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    MultiTenantConfiguration::create(
                        'tenant',
                        [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ]
                    ),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->sendCommand(
            new RegisterTicket('123', 'Johnny', 'alert'),
            metadata: ['tenant' => 'tenant_a']
        );
        $ecotone->sendCommand(
            new RegisterTicket('122', 'Johnny', 'alert'),
            metadata: ['tenant' => 'tenant_b']
        );

        self::assertEquals(
            [['ticket_id' => '123', 'ticket_type' => 'alert']],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_a'])
        );
        self::assertEquals(
            [['ticket_id' => '122', 'ticket_type' => 'alert']],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_b'])
        );

        $ecotone->sendCommand(
            new RegisterTicket('124', 'Johnny', 'alert'),
            metadata: ['tenant' => 'tenant_b']
        );
        $ecotone->sendCommand(
            new CloseTicket('123'),
            metadata: ['tenant' => 'tenant_a']
        );

        self::assertEquals(
            [],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_a'])
        );
        self::assertEquals(
            [
                ['ticket_id' => '122', 'ticket_type' => 'alert'],
                ['ticket_id' => '124', 'ticket_type' => 'alert'],
            ],
            $ecotone->sendQueryWithRouting('getInProgressTickets', metadata: ['tenant' => 'tenant_b'])
        );
    }

    public function test_multi_tenancy_do_not_work_with_polling_endpoint(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [
                new InProgressTicketList(), new TicketEventConverter(),
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
                'logger' => new EchoLogger(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    MultiTenantConfiguration::create(
                        'tenant',
                        [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ]
                    ),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->sendCommand(
            new RegisterTicket('123', 'Johnny', 'alert'),
            metadata: ['tenant' => 'tenant_a']
        );

        $this->expectException(InvalidArgumentException::class);

        $ecotone->run(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
    }
}
