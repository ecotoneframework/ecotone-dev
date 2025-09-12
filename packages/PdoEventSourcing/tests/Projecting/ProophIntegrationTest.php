<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\Lite\EcotoneLite;
use Enqueue\Dbal\DbalConnectionFactory;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcing\Projecting\Fixture\DbalTicketProjection;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\CreateTicketCommand;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\Ticket;

class ProophIntegrationTest extends ProjectingTestCase
{
    public function test_it_can_project_events(): void
    {
        if (! \class_exists(DbalConnectionFactory::class)) {
            self::markTestSkipped('Dbal not installed');
        }
        $connectionFactory = self::getConnectionFactory();
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [DbalTicketProjection::class, Ticket::class],
            [DbalConnectionFactory::class => $connectionFactory, DbalTicketProjection::class => new DbalTicketProjection($connectionFactory->establishConnection())],
            runForProductionEventStore: true
        );

        $ticketsCount = $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection(DbalTicketProjection::NAME)
            ->sendCommand(new CreateTicketCommand($ticketId = Uuid::uuid4()->toString()))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId])
            ->sendQueryWithRouting("getTicketsCount");

        self::assertSame(1, $ticketsCount);

        $ticketsCount = $ecotone->deleteProjection(DbalTicketProjection::NAME)
            ->initializeProjection(DbalTicketProjection::NAME)
            ->sendQueryWithRouting("getTicketsCount");

        self::assertSame(0, $ticketsCount);

        $ticketsCount = $ecotone->triggerProjection(DbalTicketProjection::NAME)
            ->sendQueryWithRouting("getTicketsCount");

        self::assertSame(1, $ticketsCount);
    }
}