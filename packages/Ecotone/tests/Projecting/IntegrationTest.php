<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Modelling\Event;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Projecting\Fixture\TicketCreated;
use Test\Ecotone\Projecting\Fixture\TicketProjection;

class IntegrationTest extends TestCase
{
    public function test_it_can_project_events(): void
    {
        $streamSource = new InMemoryStreamSource();
        $projection = new TicketProjection();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [TicketProjection::class],
            ['ticket_stream_source' => $streamSource, TicketProjection::class => $projection],
        );

        $streamSource->append(
            Event::create(new TicketCreated('ticket-1')),
            Event::create(new TicketCreated('ticket-4')),
        );
        self::assertEquals([], $projection->getProjectedEvents());

        $ecotone->publishEvent(new TicketCreated('ticket-that-triggers-projection'));

        self::assertEquals([
            new TicketCreated('ticket-1'),
            new TicketCreated('ticket-4'),
        ], $projection->getProjectedEvents());

        $ecotone->publishEvent(new TicketCreated('ticket-that-triggers-projection'));

        self::assertEquals([
            new TicketCreated('ticket-1'),
            new TicketCreated('ticket-4'),
        ], $projection->getProjectedEvents());
    }

}