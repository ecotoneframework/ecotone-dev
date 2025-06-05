<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProjectionHandlersExecutionRoutingTest;

use Ecotone\EventSourcing\Attribute\Stream;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate, Stream(self::STREAM_NAME)]
class AnAggregate
{
    public const STREAM_NAME = 'an_aggregate_stream';
    use WithAggregateVersioning;

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