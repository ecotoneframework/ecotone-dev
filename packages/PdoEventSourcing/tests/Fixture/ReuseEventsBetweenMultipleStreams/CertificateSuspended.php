<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ReuseEventsBetweenMultipleStreams;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::NAME)]
final class CertificateSuspended
{
    public const NAME = 'certificate.suspended';

    public function __construct(
        public int $cycleId,
        public int $certificateId,
    ) {
    }
}
