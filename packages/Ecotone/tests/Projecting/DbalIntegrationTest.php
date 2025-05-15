<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting;

use Ecotone\EventSourcing\Prooph\Projecting\EventStoreStreamSourceBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\AggregateMessage;
use Ecotone\Projecting\Config\ProjectingConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Projecting\Fixture\CreateTicketCommand;
use Test\Ecotone\Projecting\Fixture\Ticket;
use Test\Ecotone\Projecting\Fixture\TicketAssigned;
use Test\Ecotone\Projecting\Fixture\TicketCreated;
use Test\Ecotone\Projecting\Fixture\TicketProjection;

class DbalIntegrationTest extends TestCase
{
    public function test_it_can_project_events(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [TicketProjection::class, Ticket::class],
            [
                TicketProjection::class => $projection = new TicketProjection(),
                DbalConnectionFactory::class => $this->getConnectionFactory()
            ],
            ServiceConfiguration::createWithDefaults()
                ->addExtensionObject(new EventStoreStreamSourceBuilder('ticket_stream_source', Ticket::STREAM_NAME))
                ->addExtensionObject(ProjectingConfiguration::createDbal())
            ,
            runForProductionEventStore: true,
        );

        $ecotone->deleteEventStream(Ticket::STREAM_NAME);

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

    private function getConnectionFactory(): DbalConnectionFactory
    {
        return new DbalConnectionFactory(getenv('DATABASE_DSN'));
    }
}