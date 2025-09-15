<?php
/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\InMemory\InMemoryStreamSourceBuilder;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketCreated;
use Test\Ecotone\EventSourcing\Projecting\Fixture\TicketProjection;

class IntegrationTest extends ProjectingTestCase
{
    public function test_it_can_project_events(): void
    {
        $projection = new TicketProjection();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [TicketProjection::class],
            [$projection, self::getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->addExtensionObject($streamSource = new InMemoryStreamSourceBuilder([TicketProjection::NAME], partitionField: MessageHeaders::EVENT_AGGREGATE_ID))
        );

        $streamSource->append(
            Event::create(new TicketCreated('ticket-1'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-1']),
            Event::create(new TicketCreated('ticket-4'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-4']),
        );
        self::assertEquals([], $projection->getProjectedEvents());

        $ecotone->publishEvent(new TicketCreated('ticket-that-triggers-projection'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-1']);
        $ecotone->publishEvent(new TicketCreated('ticket-that-triggers-projection'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-1']);

        self::assertEquals([
            new TicketCreated('ticket-1'),
        ], $projection->getProjectedEvents());

        $ecotone->publishEvent(new TicketCreated('ticket-that-triggers-projection'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-4']);

        self::assertEquals([
            new TicketCreated('ticket-1'),
            new TicketCreated('ticket-4'),
        ], $projection->getProjectedEvents());

        $streamSource->append(
            Event::create(new TicketCreated('ticket-4'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-4']),
        );

        $ecotone->publishEvent(new TicketCreated('ticket-that-triggers-projection'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-4']);
        self::assertEquals([
            new TicketCreated('ticket-1'),
            new TicketCreated('ticket-4'),
            new TicketCreated('ticket-4'),
        ], $projection->getProjectedEvents());
    }

}