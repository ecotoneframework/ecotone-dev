<?php

namespace Test\Ecotone\JMSConverter\Fixture\Configuration\UnionConverter;

/**
 * licence Apache-2.0
 */
class StandardAppointmentType implements AppointmentType
{
    public const STANDARD = 'standard';

    public function getType(): string
    {
        return self::STANDARD;
    }
}
