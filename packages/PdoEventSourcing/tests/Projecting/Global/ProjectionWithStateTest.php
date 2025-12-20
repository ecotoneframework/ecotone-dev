<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionState;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class ProjectionWithStateTest extends ProjectingTestCase
{
    public function test_projection_should_be_able_to_keep_the_state_between_runs(): void
    {
        $projection = $this->createCounterProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        self::assertEquals(1, $ecotone->sendQueryWithRouting('ticket.getCurrentCount'));
        self::assertEquals(0, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));

        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'));
        $ecotone->sendCommand(new CloseTicket('124'));

        self::assertEquals(2, $ecotone->sendQueryWithRouting('ticket.getCurrentCount'));
        self::assertEquals(1, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));

        $ecotone->sendCommand(new CloseTicket('123'));

        self::assertEquals(2, $ecotone->sendQueryWithRouting('ticket.getCurrentCount'));
        self::assertEquals(2, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));
    }

    public function test_projection_state_should_be_reset_together_with_projection(): void
    {
        $projection = $this->createCounterProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'))
            ->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'))
            ->sendCommand(new CloseTicket('124'))
            ->sendCommand(new CloseTicket('123'))
            ->resetProjection($projection::NAME)
            ->triggerProjection($projection::NAME);

        self::assertEquals(2, $ecotone->sendQueryWithRouting('ticket.getCurrentCount'));
        self::assertEquals(2, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));

        $ecotone->sendCommand(new RegisterTicket('125', 'Johnny', 'alert'));

        self::assertEquals(3, $ecotone->sendQueryWithRouting('ticket.getCurrentCount'));
        self::assertEquals(2, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));
    }

    public function test_triggering_projection_with_state_synchronously(): void
    {
        $projection = $this->createCounterProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'))
            ->sendCommand(new CloseTicket('123'));

        self::assertEquals(1, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));
    }

    private function createCounterProjection(): object
    {
        return new #[ProjectionV2(self::NAME), FromStream(Ticket::class)] class () {
            public const NAME = 'ticket_counter';

            private int $ticketCount = 0;
            private int $closedTicketCount = 0;

            #[EventHandler]
            public function onTicketRegistered(TicketWasRegistered $event, #[ProjectionState] array $state = []): array
            {
                $state['ticketCount'] = ($state['ticketCount'] ?? 0) + 1;
                $this->ticketCount = $state['ticketCount'];
                $this->closedTicketCount = $state['closedTicketCount'] ?? 0;
                return $state;
            }

            #[EventHandler]
            public function onTicketClosed(TicketWasClosed $event, #[ProjectionState] array $state = []): array
            {
                $state['closedTicketCount'] = ($state['closedTicketCount'] ?? 0) + 1;
                $this->ticketCount = $state['ticketCount'] ?? 0;
                $this->closedTicketCount = $state['closedTicketCount'];
                return $state;
            }

            #[QueryHandler('ticket.getCurrentCount')]
            public function getCurrentCount(): int
            {
                return $this->ticketCount;
            }

            #[QueryHandler('ticket.getClosedCount')]
            public function getClosedCount(): int
            {
                return $this->closedTicketCount;
            }
        };
    }

    private function bootstrapEcotone(array $classesToResolve, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [Ticket::class, TicketEventConverter::class]),
            containerOrAvailableServices: array_merge($services, [new TicketEventConverter(), self::getConnectionFactory()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
