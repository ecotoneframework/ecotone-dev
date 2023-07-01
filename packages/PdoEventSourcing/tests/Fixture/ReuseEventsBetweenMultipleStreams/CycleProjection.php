<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ReuseEventsBetweenMultipleStreams;

use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\Modelling\Attribute\EventHandler;

#[Projection(self::NAME, Cycle::STREAM)]
final class CycleProjection
{
    public const NAME = 'cycle_projection';

    #[EventHandler(CertificateIssued::NAME)]
    public function whenCertificateIssued(CertificateIssued $event, array $metadata, EventStreamEmitter $eventStreamEmitter): void
    {
        $eventStreamEmitter->copyTo(Certificate::class, [$event], $metadata);
    }
}
