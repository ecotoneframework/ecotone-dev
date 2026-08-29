<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
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
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new NotificationService(), new InProgressTicketList($this->getConnection()), new TicketListUpdatedConverter(), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection',
                ])
                ->withExtensionObjects(array_merge([EventSourcingConfiguration::createWithDefaults()], $extensionObjects)),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function assertState(FlowTestSupport $ecotone, string $ticketId, int $notificationsCount): void
    {
        self::assertEquals($ticketId, $ecotone->sendQueryWithRouting('get.notifications'));
        self::assertCount($notificationsCount, $ecotone->sendQueryWithRouting('get.published_events'));
    }
}
