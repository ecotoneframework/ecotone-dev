<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\EventNameFiltering;

use Ecotone\EventSourcing\Attribute\Stream;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate, Stream(self::STREAM_NAME)]
class MultiEventAggregate
{
    use WithAggregateVersioning;
    public const STREAM_NAME = 'multi_event_aggregate_stream';

    #[Identifier]
    private string $id;

    #[CommandHandler('createMultiEvent')]
    public static function create(string $id): array
    {
        return [new FirstEvent($id), new SecondEvent($id)];
    }

    #[EventSourcingHandler]
    public function onFirstEvent(FirstEvent $event): void
    {
        $this->id = $event->id;
    }

    #[EventSourcingHandler]
    public function onSecondEvent(SecondEvent $event): void
    {
        // No-op, just for event sourcing
    }
}
