<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ReuseEventsBetweenMultipleStreams;

use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\Modelling\Attribute\EventHandler;

#[Projection(self::NAME, Certificate::STREAM)]
final class CertificateProjection
{
    public const NAME = 'certificate_projection';

    #[EventHandler(CertificateSuspended::NAME)]
    public function whenCertificateSuspended(CertificateSuspended $event, array $metadata, EventStreamEmitter $eventStreamEmitter): void
    {
        $eventStreamEmitter->copyTo(Cycle::class, [$event], $metadata);
    }
}
