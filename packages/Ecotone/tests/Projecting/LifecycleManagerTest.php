<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\InMemory\InMemoryStreamSource;
use Ecotone\Projecting\InMemory\InMemoryStreamSourceBuilder;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Projecting\Fixture\TicketProjectionWithLifecycle;
use Test\Ecotone\Projecting\Fixture\Ticket\TicketCreated;

class LifecycleManagerTest extends TestCase
{
    public function test_it_can_init_projection_lifecycle_state(): void
    {
        $streamSource = new InMemoryStreamSource();
        $projection = new TicketProjectionWithLifecycle();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [TicketProjectionWithLifecycle::class],
            ['ticket_stream_source' => $streamSource, TicketProjectionWithLifecycle::class => $projection],
            ServiceConfiguration::createWithDefaults()
                ->addExtensionObject(new InMemoryStreamSourceBuilder([TicketProjectionWithLifecycle::NAME], 'ticket_stream_source'))
        );

        $streamSource->append(
            Event::create(new TicketCreated('ticket-1'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-1']),
            Event::create(new TicketCreated('ticket-4'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-4']),
        );
        self::assertEquals([], $projection->getProjectedEvents());

        $ecotone->publishEvent(new TicketCreated('ticket-that-triggers-projection'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-1']);
    }
}