<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
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
/**
 * licence Apache-2.0
 * @internal
 */
final class AggregateAndProjectionTriggerTest extends EventSourcingMessagingTestCase
{
    public function test_triggering_projection_with_state_synchronously()
    {
        $ecotoneLite = $this->bootstrapFlowTesting(
            [],
            [new TicketEventConverter(), new StateAndEventConverter(), new NotificationService(), new TicketCounterProjection(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            enabledModules: [ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            licenceKey: LicenceTesting::VALID_LICENCE,
            namespaces: ['Test\Ecotone\EventSourcing\Fixture\Ticket', 'Test\Ecotone\EventSourcing\Fixture\TicketProjectionState'],
        );

        $ecotoneLite->initializeProjection(TicketCounterProjection::NAME);

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
