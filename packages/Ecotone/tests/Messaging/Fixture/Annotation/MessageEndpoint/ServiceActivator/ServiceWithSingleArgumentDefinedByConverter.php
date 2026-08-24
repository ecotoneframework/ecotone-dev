<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\ServiceActivator;

use Ecotone\Api\Reference;
use Ecotone\Api\ServiceActivator;
use stdClass;

/**
 * licence Apache-2.0
 */
class ServiceWithSingleArgumentDefinedByConverter
{
    #[ServiceActivator('requestChannel')]
    public function receive(#[Reference] stdClass $data)
    {
        return $data;
    }
}
