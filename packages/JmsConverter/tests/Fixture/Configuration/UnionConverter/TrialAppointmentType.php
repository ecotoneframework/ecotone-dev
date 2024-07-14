<?php

namespace Test\Ecotone\JMSConverter\Fixture\Configuration\UnionConverter;

/**
 * licence Apache-2.0
 */
class TrialAppointmentType implements AppointmentType
{
    public const TRIAL = 'trial';

    public function getType(): string
    {
        return self::TRIAL;
    }
}
