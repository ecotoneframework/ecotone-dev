<?php

namespace App\Tests;

use App\Domain\Event\CustomerRegistered;
use Ecotone\Api\EventHandler;

class ClassExcludedFromClassmapWithAttribute
{
    #[EventHandler(endpointId: "test")]
    public function event_handler_for_tests(CustomerRegistered $event) : void
    {
        echo "Event handler for tests\n";
    }

}