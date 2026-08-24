<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProjectionHandlersExecutionRoutingTest;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate, Stream(self::STREAM_NAME)]
class AnAggregate
{
    use WithAggregateVersioning;
    public const STREAM_NAME = 'an_aggregate_stream';

    #[Identifier]
    private string $id;

    #[CommandHandler('create')]
    public static function create(string $id): array
    {
        return [new AnEvent($id)];
    }

    #[EventSourcingHandler]
    public function onEvent(AnEvent $event): void
    {
        $this->id = $event->id;
    }
}
