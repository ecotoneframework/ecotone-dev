<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore;

class LogEventId
{
    public function __construct(
        public readonly int $transactionId,
        public readonly int $sequenceNumber,
    ) {
    }

    public static function start(): self
    {
        return new self(0, 0);
    }

    public function isAfter(self $logEventId): bool
    {
        return $this->transactionId > $logEventId->transactionId || ($this->transactionId === $logEventId->transactionId && $this->sequenceNumber > $logEventId->sequenceNumber);
    }

    public function isBefore(self $logEventId): bool
    {
        return $this->transactionId < $logEventId->transactionId || ($this->transactionId === $logEventId->transactionId && $this->sequenceNumber < $logEventId->sequenceNumber);
    }

    public function isAfterOrEqual(self $logEventId): bool
    {
        return $this->isAfter($logEventId) || $this->equals($logEventId);
    }

    public function isBeforeOrEqual(self $logEventId): bool
    {
        return $this->isBefore($logEventId) || $this->equals($logEventId);
    }

    public function equals(self $logEventId): bool
    {
        return $this->transactionId === $logEventId->transactionId && $this->sequenceNumber === $logEventId->sequenceNumber;
    }
}