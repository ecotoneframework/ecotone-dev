<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ReuseEventsBetweenMultipleStreams;

final class RegisterCertificate
{
    public function __construct(
        public int $cycleId,
        public int $certificateId,
    ) {
    }
}
