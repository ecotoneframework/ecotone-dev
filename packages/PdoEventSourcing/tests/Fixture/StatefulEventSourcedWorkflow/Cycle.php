<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflow;

use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\EventSourcingSaga;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Modelling\WithEvents;

#[EventSourcingSaga(withInternalEventRecorder: true)]
class Cycle
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
        routingKey: 'cycle.submitAnAudit',
        outputChannelName: 'cycle.validateAnAudit',
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
        routingKey: 'cycle.submitAnAudit',
        outputChannelName: 'cycle.validateAnAudit',
        identifierMetadataMapping: ['cycleId' => 'cycleId'],
    )]
    public function submitAnAudit(#[Header('audit')] Audit $audit): Audit
    {
        return $audit;
    }

    #[CommandHandler(
        routingKey: 'cycle.validateAnAudit',
        outputChannelName: 'cycle.conductAnAudit'
    )]
    public function validateAnAudit(#[Header('audit')] Audit $audit): Audit
    {
        // some validations

        return $audit;
    }

    #[CommandHandler(
        routingKey: 'cycle.conductAnAudit',
        outputChannelName: 'cycle.issueACertificate'
    )]
    public function conductAnAudit(#[Payload] Audit $audit): ?Certificate
    {
        $this->recordThat(new AuditConducted($this->cycleId, $audit->auditId));

        return $audit->certificate;
    }

    #[CommandHandler(
        routingKey: 'cycle.issueACertificate',
    )]
    public function issueACertificate(#[Payload] Certificate $certificate): void
    {
        $this->recordThat(new CertificateIssued($this->cycleId, $certificate->certificateId));
    }

    #[QueryHandler('cycle.conductedAudits')]
    public function conductedAudits(): array
    {
        return $this->audits;
    }

    #[QueryHandler('cycle.issuedCertificates')]
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
