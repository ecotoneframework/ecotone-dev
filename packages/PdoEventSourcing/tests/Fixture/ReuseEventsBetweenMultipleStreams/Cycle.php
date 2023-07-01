<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ReuseEventsBetweenMultipleStreams;

use Ecotone\EventSourcing\Attribute\AggregateType;
use Ecotone\EventSourcing\Attribute\Stream;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
#[AggregateType(self::AGGREGATE_TYPE)]
#[Stream(self::STREAM)]
final class Cycle
{
    use WithAggregateVersioning;

    public const STREAM = self::AGGREGATE_TYPE;
    private const AGGREGATE_TYPE = 'cycle';

    #[AggregateIdentifier]
    private int $cycleId;

    /** @var array<int> */
    private array $activeCertificates = [];

    #[CommandHandler]
    public static function startCycleWithCertificate(RegisterCertificate $command): array
    {
        return [
            new CycleStarted($command->cycleId),
            new CertificateIssued($command->cycleId, $command->certificateId),
        ];
    }

    #[CommandHandler]
    public function registerCertificate(RegisterCertificate $command): array
    {
        return [
            new CertificateIssued($command->cycleId, $command->certificateId),
        ];
    }

    #[EventSourcingHandler]
    public function applyCycleStarted(CycleStarted $event): void
    {
        $this->cycleId = $event->cycleId;
    }

    #[EventSourcingHandler]
    public function applyCertificateIssued(CertificateIssued $event): void
    {
        $this->activeCertificates[] = sprintf('certificate.%d', $event->certificateId);
    }

    #[EventSourcingHandler]
    public function applyCertificateSuspended(CertificateSuspended $event): void
    {
        $this->activeCertificates = array_diff($this->activeCertificates, [sprintf('certificate.%d', $event->certificateId)]);
    }

    #[QueryHandler(routingKey: 'cycle.activeCertificates')]
    public function activeCertificates(): array
    {
        return array_values($this->activeCertificates);
    }
}
