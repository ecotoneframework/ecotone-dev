<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\EventHandler\Aggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\EventHandler;
use stdClass;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class AggregateEventHandlerWithListenToRegex
{
    #[EventHandler('order.*')]
    public function execute(stdClass $class): void
    {
    }
}
