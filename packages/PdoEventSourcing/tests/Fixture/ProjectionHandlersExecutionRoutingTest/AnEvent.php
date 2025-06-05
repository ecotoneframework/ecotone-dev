<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProjectionHandlersExecutionRoutingTest;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::NAME)]
class AnEvent
{
    public const NAME = 'test.an_event';

    public function __construct(public readonly string $id)
    {
    }
}