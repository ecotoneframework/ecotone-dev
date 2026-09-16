<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\ServiceActivator;

use Ecotone\Api\InternalHandler;
use Ecotone\Api\Reference;
use stdClass;

/**
 * licence Apache-2.0
 */
class ServiceWithSingleArgumentDefinedByConverter
{
    #[InternalHandler('requestChannel')]
    public function receive(#[Reference] stdClass $data)
    {
        return $data;
    }
}
