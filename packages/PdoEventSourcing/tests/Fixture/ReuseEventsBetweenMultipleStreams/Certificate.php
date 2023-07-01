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
final class Certificate
{
    use WithAggregateVersioning;

    public const STREAM = self::AGGREGATE_TYPE;
    private const AGGREGATE_TYPE = 'certificate';

    #[AggregateIdentifier]
    private int $certificateId;
    private int $cycleId;
    private bool $suspended = false;

    #[CommandHandler(routingKey: 'certificate.suspend')]
    public function suspend(): array
    {
        return [new CertificateSuspended($this->cycleId, $this->certificateId)];
    }

    #[EventSourcingHandler]
    public function applyCertificateIssued(CertificateIssued $event): void
    {
        $this->cycleId = $event->cycleId;
        $this->certificateId = $event->certificateId;
    }

    #[EventSourcingHandler]
    public function applyCertificateSuspended(CertificateSuspended $event): void
    {
        $this->suspended = true;
    }

    #[QueryHandler(routingKey: 'certificate.isSuspended')]
    public function isSuspended(): bool
    {
        return $this->suspended;
    }
}
