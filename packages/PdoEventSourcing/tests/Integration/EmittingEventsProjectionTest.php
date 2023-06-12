<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\EventSourcing\Prooph\ProophProjectionRunningOption;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
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

    public function test_when_projection_is_deleted_emitted_events_will_be_removed_too(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $ecotone->initializeProjection(InProgressTicketList::NAME)
            ->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'))
            ->sendCommand(new CloseTicket('123'))
            ->deleteProjection(InProgressTicketList::NAME)
        ;

        self::assertNull($ecotone->sendQueryWithRouting('get.notifications'));
    }

    public function test_projection_emitting_events_should_not_republished_in_case_replaying_projection(): void
    {
        $ecotone = $this->bootstrapEcotone([
            ProjectionRunningConfiguration::createEventDriven(InProgressTicketList::NAME)
                ->withTestingSetup()
                ->withOption(ProophProjectionRunningOption::OPTION_LOAD_COUNT, 2),
        ]);
        $ecotone->initializeProjection(InProgressTicketList::NAME)
            ->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'))
            ->sendCommand(new RegisterTicket('124', 'Johnny', 'info'))
            ->resetProjection(InProgressTicketList::NAME)
        ;

        $this->assertState(ecotone: $ecotone, ticketId: '124', notificationsCount: 2);
    }

    private function bootstrapEcotone(array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new NotificationService(), new InProgressTicketList($this->getConnection()), new TicketListUpdatedConverter(), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames([ModulePackageList::AMQP_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection',
                ])
                ->withExtensionObjects(array_merge([EventSourcingConfiguration::createWithDefaults()], $extensionObjects)),
            pathToRootCatalog: __DIR__ . '/../../'
        );
    }

    private function assertState(FlowTestSupport $ecotone, string $ticketId, int $notificationsCount): void
    {
        self::assertEquals($ticketId, $ecotone->sendQueryWithRouting('get.notifications'));
        self::assertCount($notificationsCount, $ecotone->sendQueryWithRouting('get.published_events'));
    }
}
