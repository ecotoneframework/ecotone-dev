<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting;

use Ecotone\EventSourcing\Prooph\Projecting\EventStoreAggregateStreamSourceBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\TestConfiguration;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\Config\ProjectingConfiguration;
use Ecotone\Projecting\InMemory\InMemoryStreamSource;
use Ecotone\Projecting\InMemory\InMemoryStreamSourceBuilder;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Projecting\Fixture\AsynchronousProjection;
use Test\Ecotone\Projecting\Fixture\Ticket;
use Test\Ecotone\Projecting\Fixture\TicketCreated;

class AsynchronousProjectionTest extends TestCase
{
    public function test_it_can_init_projection_lifecycle_state(): void
    {
        $streamSource = new InMemoryStreamSource();
        $projection = new AsynchronousProjection();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [AsynchronousProjection::class],
            ['ticket_stream_source' => $streamSource, AsynchronousProjection::class => $projection],
            ServiceConfiguration::createWithDefaults()
                ->addExtensionObject(new InMemoryStreamSourceBuilder([AsynchronousProjection::NAME], 'ticket_stream_source'))
            ,
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel(AsynchronousProjection::ASYNC_CHANNEL),
            ],
        );

        $streamSource->append(
            Event::create(new TicketCreated('ticket-1'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-1']),
            Event::create(new TicketCreated('ticket-4'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-4']),
        );
        $ecotone->publishEvent(new TicketCreated('ticket-that-triggers-projection'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-1']);
        self::assertEquals([], $projection->getProjectedEvents());

        $ecotone->run(AsynchronousProjection::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals([new TicketCreated('ticket-1')], $projection->getProjectedEvents());

        $ecotone->publishEvent(new TicketCreated('ticket-that-triggers-projection'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-1']);
        $ecotone->run(AsynchronousProjection::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup()->withFinishWhenNoMessages(true));

        self::assertEquals([new TicketCreated('ticket-1')], $projection->getProjectedEvents());
    }
}