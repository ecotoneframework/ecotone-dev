<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\EventNameFiltering;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::NAME)]
class FirstEvent
{
    public const NAME = 'test.first_event';

    public function __construct(public readonly string $id)
    {
    }
}
