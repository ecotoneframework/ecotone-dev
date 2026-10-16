<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection\InProgressTicketList;
use Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection\NotificationService;
use Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection\TicketListUpdatedConverter;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class EmittingEventsProjectionTest extends EventSourcingMessagingTestCase
{
    public function test_projection_emitting_events(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $ecotone->initializeProjection(InProgressTicketList::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $this->assertState(ecotone: $ecotone, ticketId: '123', notificationsCount: 1);

        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'info'));
        $this->assertState(ecotone: $ecotone, ticketId: '124', notificationsCount: 2);

        $ecotone->sendCommand(new CloseTicket('123'));
        $this->assertState(ecotone: $ecotone, ticketId: '123', notificationsCount: 3);
    }

    private function bootstrapEcotone(array $extensionObjects = []): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [new NotificationService(), new InProgressTicketList($this->getConnection()), new TicketListUpdatedConverter(), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: array_merge([EventSourcingConfiguration::createWithDefaults()], $extensionObjects),
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ],
            pathToRootCatalog: __DIR__ . '/../../',
            licenceKey: LicenceTesting::VALID_LICENCE,
            namespaces: ['Test\Ecotone\EventSourcing\Fixture\Ticket', 'Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection', ],
            environment: 'prod',
        );
        $ecotone->setupDatabaseTables();

        return $ecotone;
    }

    private function assertState(FlowTestSupport $ecotone, string $ticketId, int $notificationsCount): void
    {
        self::assertEquals($ticketId, $ecotone->sendQueryWithRouting('get.notifications'));
        self::assertCount($notificationsCount, $ecotone->sendQueryWithRouting('get.published_events'));
    }
}
