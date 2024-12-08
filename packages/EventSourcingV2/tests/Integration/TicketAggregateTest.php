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
use Test\Ecotone\EventSourcingV2\Fixture\CounterProjection\CounterProjection;
use Test\Ecotone\EventSourcingV2\Fixture\EventNotifier\EventNotifier;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\AssignTicket;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\CreateTicket;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\TicketWasAssigned;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\TicketWasCreated;
use Test\Ecotone\EventSourcingV2\Fixture\TicketPure\TicketPure;

class TicketAggregateTest extends TestCase
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

    public function aggregateClassDataProvider(): array
    {
        return [
            [Ticket::class],
            [TicketPure::class],
        ];
    }

    /**
     * @dataProvider aggregateClassDataProvider
     */
    public function test_creating_ticket(string $aggregateClass): void
    {
        $ecotone = self::bootstrapFlowTesting([$aggregateClass]);

        $ecotone->sendCommand(new CreateTicket("1"));

        $ticket = $ecotone->getAggregate($aggregateClass, "1");

        self::assertEquals("1", $ticket->getTicketId());
        self::assertEquals(null, $ticket->getAssignee());
    }

    /**
     * @dataProvider aggregateClassDataProvider
     */
    public function test_event_sourced_aggregate_events(string $aggregateClass): void
    {
        $ecotone = self::bootstrapFlowTesting([$aggregateClass, EventNotifier::class], [$eventNotifier = new EventNotifier()]);

        $ecotone->sendCommand(new CreateTicket("1"));
        $ecotone->sendCommand(new AssignTicket("1", "John"));

        self::assertEquals([
            new TicketWasCreated("1"),
            new TicketWasAssigned("1", "John")
        ], $eventNotifier->getNotifiedEvents());
    }

    /**
     * @dataProvider aggregateClassDataProvider
     */
    public function test_projection(string $aggregateClass): void
    {
        $ecotone = self::bootstrapFlowTesting([$aggregateClass, CounterProjection::class], [$counterProjection = new CounterProjection()]);

        $ecotone->sendCommand(new CreateTicket("1"));

        self::assertEquals(0, $counterProjection->count(), "Projection should not be run before initialization");
    }
}