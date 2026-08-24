<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\EventHandler\Service;

use Ecotone\Api\EventHandler;
use stdClass;

/**
 * licence Apache-2.0
 */
class ServiceEventHandlerWithListenToToRegex
{
    #[EventHandler('order.*', 'eventHandler')]
    public function execute(stdClass $class): void
    {
    }
}
