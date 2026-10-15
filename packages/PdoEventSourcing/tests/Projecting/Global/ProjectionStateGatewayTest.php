<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionState;
use Ecotone\Api\Projecting\ProjectionStateGateway;
use Ecotone\Test\LicenceTesting;
use Error;
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
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
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

    public function test_a_projection_manager_reference_given_to_the_state_gateway_is_refused_at_bootstrap(): void
    {
        $projection = $this->createCounterProjection();

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Unknown named parameter $projectionManagerReference');

        $this->bootstrapEcotone([$projection::class, CounterStateGatewayWithProjectionManagerReference::class, StateAndEventConverter::class], [$projection, new StateAndEventConverter()]);
    }

    private function createCounterProjection(): object
    {
        return new #[Projection(self::NAME), FromAggregateStream(Ticket::class)] class () {
            public const NAME = 'ticket_counter';

            #[EventHandler]
            public function onTicketRegistered(TicketWasRegistered $event, #[ProjectionState] array $state = []): array
            {
                $state['ticketCount'] = ($state['ticketCount'] ?? 0) + 1;
                $state['closedTicketCount'] ??= 0;
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
        return $this->bootstrapFlowTesting(
            classesToResolve: array_merge($classesToResolve, [Ticket::class, TicketEventConverter::class]),
            containerOrAvailableServices: array_merge($services, [new TicketEventConverter(), self::getConnectionFactory()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE, ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

interface CounterStateGatewayWithProjectionManagerReference
{
    #[ProjectionStateGateway('ticket_counter', projectionManagerReference: 'my_projection_manager')]
    public function fetchState(): CounterState;
}
