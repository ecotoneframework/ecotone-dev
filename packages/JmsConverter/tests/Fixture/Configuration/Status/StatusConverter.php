<?php

namespace Test\Ecotone\JMSConverter\Fixture\Configuration\Status;

use Ecotone\Messaging\Attribute\Converter;

/**
 * licence Apache-2.0
 */
class StatusConverter
{
    #[Converter]
    public function convertFrom(Status $status): string
    {
        return $status->getType();
    }

    #[Converter]
    public function convertTo(string $status): Status
    {
        return new Status($status);
    }
}
