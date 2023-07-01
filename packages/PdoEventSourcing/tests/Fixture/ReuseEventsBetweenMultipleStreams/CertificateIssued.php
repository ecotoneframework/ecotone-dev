<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ReuseEventsBetweenMultipleStreams;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::NAME)]
final class CertificateIssued
{
    public const NAME = 'certificate.issued';

    public function __construct(
        public int $cycleId,
        public int $certificateId,
    ) {
    }
}
