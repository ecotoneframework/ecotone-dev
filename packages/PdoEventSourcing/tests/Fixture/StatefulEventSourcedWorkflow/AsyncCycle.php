<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\EventSourcingSaga;
use Ecotone\Api\Header;
use Ecotone\Api\Identifier;
use Ecotone\Api\Payload;
use Ecotone\Api\QueryHandler;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Modelling\WithEvents;

#[EventSourcingSaga(withInternalEventRecorder: true)]
class AsyncCycle
{
    use WithEvents;
    use WithAggregateVersioning;

    #[Identifier]
    private string $cycleId;

    /**
     * @var list<string>
     */
    private array $audits = [];

    /**
     * @var list<string>
     */
    private array $certificates = [];

    #[CommandHandler(
        routingKey: 'asyncCycle.submitAnAudit',
        outputChannelName: 'asyncCycle.validateAnAudit',
        identifierMetadataMapping: ['cycleId' => 'cycleId'],
    )]
    public static function startCycleBySubmittingAnAudit(
        #[Header('cycleId')] string $cycleId
    ): self {
        $cycle = new self();
        $cycle->recordThat(new CycleStarted($cycleId));

        return $cycle;
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
        $this->recordThat(new AuditConducted($this->cycleId, $audit->auditId));

        return $audit->certificate;
    }

    #[CommandHandler(
        routingKey: 'asyncCycle.issueACertificate',
        endpointId: 'asyncCycle.issueACertificate.endpoint',
    )]
    #[Asynchronous('cycle')]
    public function issueACertificate(#[Payload] Certificate $certificate): void
    {
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

    #[EventSourcingHandler]
    public function applyCycleStarted(CycleStarted $event): void
    {
        $this->cycleId = $event->cycleId;
    }

    #[EventSourcingHandler]
    public function applyAuditConducted(AuditConducted $event): void
    {
        $this->audits[] = $event->auditId;
    }

    #[EventSourcingHandler]
    public function applyCertificateIssued(CertificateIssued $event): void
    {
        $this->certificates[] = $event->certificateId;
    }
}
