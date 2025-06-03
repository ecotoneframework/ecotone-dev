<?php

namespace Test\Ecotone\Dbal\Fixture\StatefulWorkflow;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::NAME)]
class CycleStarted
{
    public const NAME = 'cycle.started';

    public function __construct(public string $cycleId)
    {
    }
}
