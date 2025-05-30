<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::NAME)]
class CycleStarted
{
    public const NAME = 'cycle.started';

    public function __construct(public string $cycleId)
    {
    }
}
