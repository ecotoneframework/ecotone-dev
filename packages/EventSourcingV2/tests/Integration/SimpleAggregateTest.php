<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\EventSourcingV2\Fixture\EventNotifier\EventNotifier;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\AssignTicket;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\CreateTicket;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\TicketWasAssigned;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\TicketWasCreated;

class SimpleAggregateTest extends TestCase
{
    protected static function bootstrapFlowTesting(array $classesToResolve = [], array $container = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            $classesToResolve,
            $container,
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_V2_PACKAGE])),
            addInMemoryStateStoredRepository: false,
            addInMemoryEventSourcedRepository: false
        );
    }

    public function testCreatingTicket(): void
    {
        $ecotone = self::bootstrapFlowTesting([Ticket::class]);

        $ecotone->sendCommand(new CreateTicket("1"));

        $ticket = $ecotone->getAggregate(Ticket::class, "1");

        self::assertEquals("1", $ticket->getTicketId());
        self::assertEquals(null, $ticket->getAssignee());
    }

    public function test_event_sourced_aggregate_events(): void
    {
        $ecotone = self::bootstrapFlowTesting([Ticket::class, EventNotifier::class], [$eventNotifier = new EventNotifier()]);

        $ecotone->sendCommand(new CreateTicket("1"));
        $ecotone->sendCommand(new AssignTicket("1", "John"));

        self::assertEquals([
            new TicketWasCreated("1"),
            new TicketWasAssigned("1", "John")
        ], $eventNotifier->getNotifiedEvents());
    }
}