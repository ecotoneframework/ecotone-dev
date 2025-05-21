<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting;

use Ecotone\EventSourcing\Prooph\Projecting\EventStoreAggregateStreamSourceBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Projecting\Config\ProjectingConfiguration;
use Ecotone\Projecting\ProjectionStateStorage;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Projecting\Fixture\Ticket\CreateTicketCommand;
use Test\Ecotone\Projecting\Fixture\Ticket\Ticket;
use Test\Ecotone\Projecting\Fixture\Ticket\TicketAssigned;
use Test\Ecotone\Projecting\Fixture\Ticket\TicketCreated;
use Test\Ecotone\Projecting\Fixture\Ticket\TicketUnassigned;
use Test\Ecotone\Projecting\Fixture\TicketProjection;

class DbalIntegrationTest extends TestCase
{
    public function test_it_can_project_events(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [TicketProjection::class],
            [
                TicketProjection::class => $projection = new TicketProjection(),
                DbalConnectionFactory::class => $this->getConnectionFactory()
            ],
            ServiceConfiguration::createWithDefaults()
                ->addExtensionObject(new EventStoreAggregateStreamSourceBuilder('ticket_stream_source', Ticket::class, Ticket::STREAM_NAME))
                ->addExtensionObject(ProjectingConfiguration::createDbal())
                ->withNamespaces(['Test\Ecotone\Projecting\Fixture\Ticket'])
            ,
            runForProductionEventStore: true,
        );

        $ecotone->deleteEventStream(Ticket::STREAM_NAME);
        $projectionStateStorage = $ecotone->getGateway(ProjectionStateStorage::class);
        $projectionStateStorage->deleteState(TicketProjection::NAME);

        self::assertEquals([], $projection->getProjectedEvents());

        $ecotone->sendCommand(new CreateTicketCommand("ticket-10"));
        $ecotone->sendCommand(new CreateTicketCommand("ticket-20"));

        self::assertEquals([
            new TicketCreated('ticket-10'),
            new TicketCreated('ticket-20'),
        ], $projection->getProjectedEvents());

        $ecotone->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => 'ticket-10']);

        self::assertEquals([
            new TicketCreated('ticket-10'),
            new TicketCreated('ticket-20'),
            new TicketAssigned('ticket-10'),
        ], $projection->getProjectedEvents());
    }

    public function test_it_can_use_user_projection_state(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [TicketProjection::class, Ticket::class],
            [
                TicketProjection::class => $projection = new TicketProjection(),
                DbalConnectionFactory::class => $this->getConnectionFactory()
            ],
            ServiceConfiguration::createWithDefaults()
                ->addExtensionObject(new EventStoreAggregateStreamSourceBuilder('ticket_stream_source', Ticket::class, Ticket::STREAM_NAME))
                ->addExtensionObject(ProjectingConfiguration::createDbal())
            ,
            runForProductionEventStore: true,
        );

        $ecotone->deleteEventStream(Ticket::STREAM_NAME);
        $projectionStateStorage = $ecotone->getGateway(ProjectionStateStorage::class);
        $projectionStateStorage->deleteState(TicketProjection::NAME);

        self::assertEquals([], $projection->getProjectedEvents());

        $ecotone->sendCommand(new CreateTicketCommand("ticket-10"));
        $ecotone->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => 'ticket-10']);
        $ecotone->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => 'ticket-10']);

        self::assertEquals([
            new TicketCreated('ticket-10'),
            new TicketAssigned('ticket-10'),
            new TicketAssigned('ticket-10'),
        ], $projection->getProjectedEvents());

        $ecotone->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => 'ticket-10']);

        self::assertEquals(
            [
                new TicketCreated('ticket-10'),
                new TicketAssigned('ticket-10'),
                new TicketAssigned('ticket-10'),
            ],
            $projection->getProjectedEvents(),
        'A maximum of ' . TicketProjection::MAX_ASSIGNMENT_COUNT . ' successive assignment on the same ticket should be recorded'
        );

        $ecotone->sendCommandWithRoutingKey(Ticket::UNASSIGN_COMMAND, metadata: ['aggregate.id' => 'ticket-10']);

        self::assertEquals(
            [
                new TicketCreated('ticket-10'),
                new TicketAssigned('ticket-10'),
                new TicketAssigned('ticket-10'),
                new TicketUnassigned('ticket-10'),
            ],
            $projection->getProjectedEvents(),
        );
    }

    private function getConnectionFactory(): DbalConnectionFactory
    {
        return new DbalConnectionFactory(getenv('DATABASE_DSN'));
    }
}