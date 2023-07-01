<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ReuseEventsBetweenMultipleStreams;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::NAME)]
final class CycleStarted
{
    public const NAME = 'cycle.started';

    public function __construct(public int $cycleId)
    {
    }
}
