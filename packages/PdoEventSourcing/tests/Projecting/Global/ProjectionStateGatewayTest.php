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
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\CounterState;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\CounterStateV2Gateway;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\StateAndEventConverter;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class ProjectionStateGatewayTest extends ProjectingTestCase
{
    public function test_fetching_global_projection_state_via_gateway(): void
    {
        $projection = $this->createCounterProjection();

        $ecotone = $this->bootstrapEcotone([$projection::class, CounterStateV2Gateway::class, StateAndEventConverter::class], [$projection, new StateAndEventConverter()]);

        $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'alert'));
        $ecotone->sendCommand(new CloseTicket('124'));

        $gateway = $ecotone->getGateway(CounterStateV2Gateway::class);

        self::assertEquals(
            new CounterState(ticketCount: 2, closedTicketCount: 1),
            $gateway->fetchState()
        );
    }

    private function createCounterProjection(): object
    {
        return new #[ProjectionV2(self::NAME), FromStream(Ticket::class)] class () {
            public const NAME = 'ticket_counter';

            #[EventHandler]
            public function onTicketRegistered(TicketWasRegistered $event, #[ProjectionState] array $state = []): array
            {
                $state['ticketCount'] = ($state['ticketCount'] ?? 0) + 1;
                $state['closedTicketCount'] = $state['closedTicketCount'] ?? 0;
                return $state;
            }

            #[EventHandler]
            public function onTicketClosed(TicketWasClosed $event, #[ProjectionState] array $state = []): array
            {
                $state['closedTicketCount'] = ($state['closedTicketCount'] ?? 0) + 1;
                return $state;
            }

            #[QueryHandler('ticket.getCurrentCount')]
            public function getCurrentCount(#[ProjectionState] array $state = []): int
            {
                return $state['ticketCount'] ?? 0;
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
