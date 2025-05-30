<?php

namespace Test\Ecotone\Dbal\Fixture\StatefulWorkflow;

use Ecotone\Messaging\Attribute\Converter;

class EventsConverters
{
    #[Converter]
    public function convertFromCycleStarted(CycleStarted $event): array
    {
        return [
            'cycleId' => $event->cycleId,
        ];
    }

    #[Converter]
    public function convertToCycleStarted(array $payload): CycleStarted
    {
        return new CycleStarted($payload['cycleId']);
    }

    #[Converter]
    public function convertFromAuditConducted(AuditConducted $event): array
    {
        return [
            'cycleId' => $event->cycleId,
            'auditId' => $event->auditId,
        ];
    }

    #[Converter]
    public function convertToAuditConducted(array $payload): AuditConducted
    {
        return new AuditConducted($payload['cycleId'], $payload['auditId']);
    }

    #[Converter]
    public function convertFromCertificateIssued(CertificateIssued $event): array
    {
        return [
            'cycleId' => $event->cycleId,
            'certificateId' => $event->certificateId,
        ];
    }

    #[Converter]
    public function convertToCertificateIssued(array $payload): CertificateIssued
    {
        return new CertificateIssued($payload['cycleId'], $payload['certificateId']);
    }
}
