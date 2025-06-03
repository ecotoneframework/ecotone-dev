<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::NAME)]
class CertificateIssued
{
    public const NAME = 'cycle.certificateIssued';

    public function __construct(public string $cycleId, public string $certificateId)
    {
    }
}
