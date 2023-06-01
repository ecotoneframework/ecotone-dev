<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTest;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\CounterStateGateway;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\NotificationService;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\StateAndEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\TicketCounterProjection;

/**
 * @internal
 */
final class AggregateAndProjectionTriggerTest extends EventSourcingMessagingTest
{
    public function test_triggering_projection_with_state_synchronously()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [],
            [new TicketEventConverter(), new StateAndEventConverter(), new NotificationService(), new TicketCounterProjection(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\EventSourcing\Fixture\Ticket', 'Test\Ecotone\EventSourcing\Fixture\TicketProjectionState']),
            pathToRootCatalog: __DIR__ . '/../../',
            addEventSourcedRepository: false
        );

        $this->assertEquals(
            1,
            $ecotoneLite
                ->sendCommand(new RegisterTicket('123', 'johny', 'alert'))
                ->sendCommand(new CloseTicket('123'))
                ->getGateway(CounterStateGateway::class)
                ->fetchState()
                ->closedTicketCount
        );
    }
}
