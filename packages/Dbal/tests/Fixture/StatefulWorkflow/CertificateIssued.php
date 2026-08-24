<?php

namespace Test\Ecotone\Dbal\Fixture\StatefulWorkflow;

use Ecotone\Api\Attribute\NamedEvent;

#[NamedEvent(self::NAME)]
class CertificateIssued
{
    public const NAME = 'cycle.certificateIssued';

    public function __construct(public string $cycleId, public string $certificateId)
    {
    }
}
