<?php

namespace Test\Ecotone\Dbal\Fixture\StatefulWorkflow;

use Ecotone\Api\Attribute\BusinessMethod;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Parameter\Header;

interface CycleGateway
{
    #[BusinessMethod('cycle.submitAnAudit')]
    public function submitAnAudit(#[Header('cycleId')] $cycleId, #[Header('audit')] Audit $audit): void;

    #[BusinessMethod('cycle.conductedAudits')]
    public function conductedAudits(#[Identifier] $cycleId): array;

    #[BusinessMethod('cycle.issuedCertificates')]
    public function issuedCertificates(#[Identifier] $cycleId): array;
}
