<?php

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\Test\EcotoneTestSupport;
use Ecotone\Lite\Test\TestConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithInMemoryAsynchronousEventDrivenProjection\InProgressTicketList;
use Test\Ecotone\EventSourcing\Fixture\TicketWithInMemoryAsynchronousEventDrivenProjection\ProjectionConfiguration;

final class EcotoneEventSourcingTestSupportTest extends TestCase
{
    public function test_registering_in_memory_event_sourcing_repository()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapWithEventSourcing(
            [Ticket::class, TicketEventConverter::class, InProgressTicketList::class],
            [new TicketEventConverter(), new InProgressTicketList()],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment("test")
                ->withExtensionObjects([
                    EventSourcingConfiguration::createInMemory(),
                    TestConfiguration::createWithDefaults()
                ]),
            enableModulePackages: [ModulePackageList::EVENT_SOURCING_PACKAGE]
        );

        $this->assertCount(0, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));;

        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket("1", "johny", "alert"));

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));;
    }

    public function test_registering_with_asynchronous_package()
    {
        $ecotoneTestSupport = EcotoneTestSupport::boostrapWithEventSourcing(
            [Ticket::class, TicketEventConverter::class, InProgressTicketList::class, ProjectionConfiguration::class],
            [new TicketEventConverter(), new InProgressTicketList()],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment("test")
                ->withExtensionObjects([
                    EventSourcingConfiguration::createInMemory(),
                    TestConfiguration::createWithDefaults()
                ]),
            enableModulePackages: [ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]
        );

        $ecotoneTestSupport->getCommandBus()->send(new RegisterTicket("1", "johny", "alert"));

        $this->assertCount(0, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));;

        $ecotoneTestSupport->run('asynchronous_projections');

        $this->assertCount(1, $ecotoneTestSupport->getQueryBus()->sendWithRouting('getInProgressTickets'));;
    }
}
