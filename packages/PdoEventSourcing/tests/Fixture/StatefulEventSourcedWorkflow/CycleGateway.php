<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow;

use Ecotone\Api\BusinessMethod;
use Ecotone\Api\Header;
use Ecotone\Api\Identifier;

interface CycleGateway
{
    #[BusinessMethod('cycle.submitAnAudit')]
    public function submitAnAudit(#[Header('cycleId')] $cycleId, #[Header('audit')] Audit $audit): void;

    #[BusinessMethod('cycle.conductedAudits')]
    public function conductedAudits(#[Identifier] $cycleId): array;

    #[BusinessMethod('cycle.issuedCertificates')]
    public function issuedCertificates(#[Identifier] $cycleId): array;
}
