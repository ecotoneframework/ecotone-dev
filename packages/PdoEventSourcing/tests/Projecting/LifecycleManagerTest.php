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
use Test\Ecotone\EventSourcing\Projecting\Fixture\TicketProjectionWithLifecycle;

/**
 * @internal
 */
class LifecycleManagerTest extends ProjectingTestCase
{
    public function test_it_can_init_projection_lifecycle_state(): void
    {
        $projection = new TicketProjectionWithLifecycle();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [TicketProjectionWithLifecycle::class],
            [$projection, self::getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->addExtensionObject($streamSource = new InMemoryStreamSourceBuilder())
        );

        $streamSource->append(
            Event::create(new TicketCreated('ticket-1'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-1']),
            Event::create(new TicketCreated('ticket-4'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-4']),
        );
        self::assertEquals([], $projection->getProjectedEvents());

        $ecotone->publishEvent(new TicketCreated('ticket-that-triggers-projection'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-1']);
    }
}
