<?php

namespace Test\Ecotone\Dbal\Fixture\StatefulWorkflow;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::NAME)]
class AuditConducted
{
    public const NAME = 'cycle.auditConducted';

    public function __construct(public string $cycleId, public string $auditId)
    {
    }
}
