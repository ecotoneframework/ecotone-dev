<?php

declare(strict_types=1);

namespace App\Trace;

use DateTimeImmutable;

final readonly class QueryRecord
{
    public const FRESHNESS_SECONDS = 60;
    private const TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * @param array<string, mixed>|null $result
     */
    public function __construct(
        public ?string $queryId,
        public ?DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $receivedAt,
        public ?array $result,
        public ?string $failure,
    ) {
    }

    public static function empty(): self
    {
        return new self(null, null, null, null, null);
    }

    /**
     * @param array<string, mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            $stored['queryId'] ?? null,
            isset($stored['requestedAt']) ? new DateTimeImmutable($stored['requestedAt']) : null,
            isset($stored['receivedAt']) ? new DateTimeImmutable($stored['receivedAt']) : null,
            $stored['result'] ?? null,
            $stored['failure'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'queryId' => $this->queryId,
            'requestedAt' => $this->requestedAt?->format(self::TIME_FORMAT),
            'receivedAt' => $this->receivedAt?->format(self::TIME_FORMAT),
            'result' => $this->result,
            'failure' => $this->failure,
        ];
    }

    public function requested(string $queryId, DateTimeImmutable $at): self
    {
        return new self($queryId, $at, $this->receivedAt, $this->result, null);
    }

    public function requestFailed(string $failure): self
    {
        return new self($this->queryId, $this->requestedAt, $this->receivedAt, $this->result, $failure);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function received(array $result, DateTimeImmutable $at): self
    {
        return new self($this->queryId, $this->requestedAt, $at, $result, null);
    }

    public function hasOutstandingRequest(): bool
    {
        return $this->requestedAt !== null && ($this->receivedAt === null || $this->requestedAt > $this->receivedAt);
    }

    public function needsRequest(DateTimeImmutable $now, int $freshnessSeconds = self::FRESHNESS_SECONDS): bool
    {
        if ($this->hasOutstandingRequest()) {
            return self::secondsBetween($this->requestedAt, $now) >= $freshnessSeconds;
        }

        return $this->receivedAt === null || self::secondsBetween($this->receivedAt, $now) >= $freshnessSeconds;
    }

    public function stateAt(DateTimeImmutable $now, int $replyTimeoutSeconds): TraceAnswerState
    {
        if (! $this->hasOutstandingRequest()) {
            return TraceAnswerState::Answered;
        }

        return $this->failure !== null || self::secondsBetween($this->requestedAt, $now) >= $replyTimeoutSeconds
            ? TraceAnswerState::NotAnswering
            : TraceAnswerState::Waiting;
    }

    private static function secondsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return $to->getTimestamp() - $from->getTimestamp();
    }
}
