<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore;

readonly class LogEventId
{
    public function __construct(
        public int $transactionId,
        public int $sequenceNumber,
    ) {
    }

    public static function start(): self
    {
        return new self(0, 0);
    }
}