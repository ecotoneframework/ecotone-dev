<?php

namespace Test\Ecotone\Dbal\Fixture\StatefulWorkflow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Saga;
use Ecotone\Modelling\WithEvents;

#[Saga]
class AsyncCycle
{
    use WithEvents;

    /**
     * @var list<string>
     */
    private array $audits = [];

    /**
     * @var list<string>
     */
    private array $certificates = [];

    public function __construct(
        #[Identifier] private string $cycleId
    ) {
    }

    #[CommandHandler(
        routingKey: 'asyncCycle.submitAnAudit',
        outputChannelName: 'asyncCycle.validateAnAudit',
        identifierMetadataMapping: ['cycleId' => 'cycleId'],
    )]
    public static function startCycleBySubmittingAnAudit(
        #[Header('cycleId')] string $cycleId
    ): self {
        return new self($cycleId);
    }

    #[CommandHandler(
        routingKey: 'asyncCycle.submitAnAudit',
        outputChannelName: 'asyncCycle.validateAnAudit',
        identifierMetadataMapping: ['cycleId' => 'cycleId'],
    )]
    public function submitAnAudit(#[Header('audit')] Audit $audit): Audit
    {
        return $audit;
    }

    #[CommandHandler(
        routingKey: 'asyncCycle.validateAnAudit',
        outputChannelName: 'asyncCycle.conductAnAudit'
    )]
    public function validateAnAudit(#[Header('audit')] Audit $audit): Audit
    {
        // some validations

        return $audit;
    }

    #[CommandHandler(
        routingKey: 'asyncCycle.conductAnAudit',
        outputChannelName: 'asyncCycle.issueACertificate'
    )]
    public function conductAnAudit(#[Payload] Audit $audit): ?Certificate
    {
        $this->audits[] = $audit->auditId;
        $this->recordThat(new AuditConducted($this->cycleId, $audit->auditId));

        return $audit->certificate;
    }

    #[CommandHandler(
        routingKey: 'asyncCycle.issueACertificate',
        endpointId: 'asyncCycle.issueACertificate.endpoint'
    )]
    #[Asynchronous('cycle')]
    public function issueACertificate(#[Payload] Certificate $certificate): void
    {
        $this->certificates[] = $certificate->certificateId;
        $this->recordThat(new CertificateIssued($this->cycleId, $certificate->certificateId));
    }

    #[QueryHandler('asyncCycle.conductedAudits')]
    public function conductedAudits(): array
    {
        return $this->audits;
    }

    #[QueryHandler('asyncCycle.issuedCertificates')]
    public function issuedCertificates(): array
    {
        return $this->certificates;
    }
}
