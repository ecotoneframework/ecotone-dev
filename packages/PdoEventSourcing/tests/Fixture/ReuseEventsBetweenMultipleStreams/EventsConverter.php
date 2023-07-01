<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ReuseEventsBetweenMultipleStreams;

use Ecotone\Messaging\Attribute\Converter;

final class EventsConverter
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

    #[Converter]
    public function convertFromCertificateSuspended(CertificateSuspended $event): array
    {
        return [
            'cycleId' => $event->cycleId,
            'certificateId' => $event->certificateId,
        ];
    }

    #[Converter]
    public function convertToCertificateSuspended(array $payload): CertificateSuspended
    {
        return new CertificateSuspended($payload['cycleId'], $payload['certificateId']);
    }
}
