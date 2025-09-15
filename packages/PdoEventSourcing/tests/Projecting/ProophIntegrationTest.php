<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use function class_exists;

use Ecotone\Lite\EcotoneLite;
use Enqueue\Dbal\DbalConnectionFactory;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcing\Projecting\Fixture\DbalTicketProjection;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\CreateTicketCommand;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketAssigned;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
class ProophIntegrationTest extends ProjectingTestCase
{
    public function test_it_can_project_events(): void
    {
        if (! class_exists(DbalConnectionFactory::class)) {
            self::markTestSkipped('Dbal not installed');
        }
        $connectionFactory = self::getConnectionFactory();
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [DbalTicketProjection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [$connectionFactory, new DbalTicketProjection($connectionFactory->establishConnection()), new TicketEventConverter()],
            runForProductionEventStore: true
        );

        $ticketsCount = $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection(DbalTicketProjection::NAME)
            ->sendCommand(new CreateTicketCommand($ticketId = Uuid::uuid4()->toString()))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId])
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(1, $ticketsCount);
        self::assertSame('assigned', $ecotone->sendQueryWithRouting('getTicketStatus', $ticketId));

        $ticketsCount = $ecotone->deleteProjection(DbalTicketProjection::NAME)
            ->initializeProjection(DbalTicketProjection::NAME)
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(0, $ticketsCount);

        $ticketsCount = $ecotone->triggerProjection(DbalTicketProjection::NAME)
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(1, $ticketsCount);
        self::assertSame('assigned', $ecotone->sendQueryWithRouting('getTicketStatus', $ticketId));
    }
}
