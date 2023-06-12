<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\CounterState;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\CounterStateGateway;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\NotificationService;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\StateAndEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\TicketCounterProjection;

/**
 * @internal
 */
final class ProjectionWithStateTest extends EventSourcingMessagingTestCase
{
    public function test_projection_should_be_able_to_keep_the_state_between_runs(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        $this->assertState(ecotone: $ecotone, ticketCount: 1, closedTicketCount: 0);

        $ecotone->sendCommand(new RegisterTicket('1234', 'Johnny', 'alert'));
        $ecotone->sendCommand(new CloseTicket('1234'));

        $this->assertState(ecotone: $ecotone, ticketCount: 2, closedTicketCount: 1);

        $ecotone->sendCommand(new CloseTicket('123'));

        $this->assertState(ecotone: $ecotone, ticketCount: 2, closedTicketCount: 2);
    }

    public function test_projection_state_should_be_reset_together_with_projection(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'))
            ->sendCommand(new RegisterTicket('1234', 'Johnny', 'alert'))
            ->sendCommand(new CloseTicket('1234'))
            ->sendCommand(new CloseTicket('123'))
            ->resetProjection(TicketCounterProjection::NAME)
        ;
        $this->assertState(ecotone: $ecotone, ticketCount: 2, closedTicketCount: 2);

        $ecotone->sendCommand(new RegisterTicket('12345', 'Johnny', 'alert'));

        $this->assertState(ecotone: $ecotone, ticketCount: 3, closedTicketCount: 2);
    }

    public function test_failing_fast_configuration_is_turned_off_for_complex_scenario(): void
    {
        $ecotone = $this->bootstrapEcotone(failFast: false);

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'))
            ->sendCommand(new RegisterTicket('1234', 'Johnny', 'alert'))
            ->sendCommand(new CloseTicket('1234'))
            ->sendCommand(new CloseTicket('123'))
            ->resetProjection(TicketCounterProjection::NAME)
        ;
        $this->assertState(ecotone: $ecotone, ticketCount: 2, closedTicketCount: 2);

        $ecotone->sendCommand(new RegisterTicket('12345', 'Johnny', 'alert'));

        $this->assertState(ecotone: $ecotone, ticketCount: 3, closedTicketCount: 2);
    }

    private function assertState(FlowTestSupport $ecotone, int $ticketCount, int $closedTicketCount): void
    {
        self::assertEquals($ticketCount, $ecotone->sendQueryWithRouting('ticket.getCurrentCount'));
        self::assertEquals($closedTicketCount, $ecotone->sendQueryWithRouting('ticket.getClosedCount'));
        self::assertEquals(
            new CounterState($ticketCount, $closedTicketCount),
            $ecotone->getGateway(CounterStateGateway::class)->fetchState()
        );
    }

    private function bootstrapEcotone(bool $failFast = true): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [TicketCounterProjection::class],
            containerOrAvailableServices: [new TicketEventConverter(), new StateAndEventConverter(), new NotificationService(), new TicketCounterProjection(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withFailFast($failFast)
                ->withSkippedModulePackageNames([ModulePackageList::AMQP_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketProjectionState',
                ]),
            pathToRootCatalog: __DIR__ . '/../../'
        );
    }
}
