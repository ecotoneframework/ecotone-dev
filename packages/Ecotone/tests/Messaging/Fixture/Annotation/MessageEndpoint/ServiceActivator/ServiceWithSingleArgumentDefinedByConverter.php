<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\ServiceActivator;

use Ecotone\Api\Attribute\Parameter\Reference;
use Ecotone\Api\Attribute\ServiceActivator;
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
