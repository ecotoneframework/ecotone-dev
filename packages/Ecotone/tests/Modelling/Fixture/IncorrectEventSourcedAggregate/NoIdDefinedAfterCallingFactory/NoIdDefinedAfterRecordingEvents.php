<?php

namespace Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate\NoIdDefinedAfterCallingFactory;

use Ecotone\Api\AggregateEvents;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\Identifier;
use stdClass;

#[EventSourcingAggregate]
/**
 * licence Apache-2.0
 */
class NoIdDefinedAfterRecordingEvents
{
    #[Identifier]
    private $id;

    #[CommandHandler]
    public static function create(CreateNoIdDefinedAggregate $command): array
    {
        return [];
    }

    #[AggregateEvents]
    public function recordedEvents(): array
    {
        return [new stdClass()];
    }
}
