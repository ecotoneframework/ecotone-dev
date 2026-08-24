<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow;

use Ecotone\Api\NamedEvent;

#[NamedEvent(self::NAME)]
class AuditConducted
{
    public const NAME = 'cycle.auditConducted';

    public function __construct(public string $cycleId, public string $auditId)
    {
    }
}
