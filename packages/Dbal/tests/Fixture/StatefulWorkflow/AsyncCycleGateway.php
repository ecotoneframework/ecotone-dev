<?php

namespace Test\Ecotone\Dbal\Fixture\StatefulWorkflow;

use Ecotone\Messaging\Attribute\BusinessMethod;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\Identifier;

interface AsyncCycleGateway
{
    #[BusinessMethod('asyncCycle.submitAnAudit')]
    public function submitAnAudit(#[Header('cycleId')] $cycleId, #[Header('audit')] Audit $audit): void;

    #[BusinessMethod('asyncCycle.conductedAudits')]
    public function conductedAudits(#[Identifier] $cycleId): array;

    #[BusinessMethod('asyncCycle.issuedCertificates')]
    public function issuedCertificates(#[Identifier] $cycleId): array;
}
