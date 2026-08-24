<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow;

use Ecotone\Api\Attribute\BusinessMethod;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Parameter\Header;

interface AsyncCycleGateway
{
    #[BusinessMethod('asyncCycle.submitAnAudit')]
    public function submitAnAudit(#[Header('cycleId')] $cycleId, #[Header('audit')] Audit $audit): void;

    #[BusinessMethod('asyncCycle.conductedAudits')]
    public function conductedAudits(#[Identifier] $cycleId): array;

    #[BusinessMethod('asyncCycle.issuedCertificates')]
    public function issuedCertificates(#[Identifier] $cycleId): array;
}
