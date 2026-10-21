<?php

declare(strict_types=1);

namespace App\Snapshot;

use DateTimeImmutable;

final readonly class ServiceRecord
{
    private const TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public ?DateTimeImmutable $receivedAt,
        public array $snapshot,
        public ?DateTimeImmutable $requestedAt,
        public ?string $requestFailure,
        public ?string $replyError,
        public ?DateTimeImmutable $followUpDueAt = null,
        public bool $retrying = false,
        public ?string $missingDestination = null,
    ) {
    }

    public static function empty(): self
    {
        return new self(null, [], null, null, null);
    }

    /**
     * @param array<string, mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            isset($stored['receivedAt']) ? new DateTimeImmutable($stored['receivedAt']) : null,
            $stored['snapshot'] ?? [],
            isset($stored['requestedAt']) ? new DateTimeImmutable($stored['requestedAt']) : null,
            $stored['requestFailure'] ?? null,
            $stored['replyError'] ?? null,
            isset($stored['followUpDueAt']) ? new DateTimeImmutable($stored['followUpDueAt']) : null,
            $stored['retrying'] ?? false,
            $stored['missingDestination'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'receivedAt' => $this->receivedAt?->format(self::TIME_FORMAT),
            'snapshot' => $this->snapshot,
            'requestedAt' => $this->requestedAt?->format(self::TIME_FORMAT),
            'requestFailure' => $this->requestFailure,
            'replyError' => $this->replyError,
            'followUpDueAt' => $this->followUpDueAt?->format(self::TIME_FORMAT),
            'retrying' => $this->retrying,
            'missingDestination' => $this->missingDestination,
        ];
    }

    public function requested(DateTimeImmutable $at, int $replyTimeoutSeconds): self
    {
        return $this->with([
            'requestedAt' => $at,
            'requestFailure' => null,
            'replyError' => null,
            'missingDestination' => null,
            'followUpDueAt' => $this->followUpDueAt !== null && $at >= $this->followUpDueAt ? null : $this->followUpDueAt,
            'retrying' => $this->missingDestination === null && $this->hasOutstandingRequest() && $this->isUnanswered($at, $replyTimeoutSeconds),
        ]);
    }

    public function channelMissing(string $destination, string $failure): self
    {
        return $this->with(['missingDestination' => $destination, 'requestFailure' => $failure]);
    }

    public function withFollowUpDueAt(DateTimeImmutable $at): self
    {
        return $this->with(['followUpDueAt' => $at]);
    }

    public function hasPendingFollowUp(): bool
    {
        return $this->followUpDueAt !== null;
    }

    public function requestFailed(string $failure): self
    {
        return $this->with(['requestFailure' => $failure]);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function received(array $snapshot, DateTimeImmutable $at): self
    {
        return $this->with(['receivedAt' => $at, 'snapshot' => $snapshot, 'requestFailure' => null, 'replyError' => null, 'retrying' => false, 'missingDestination' => null]);
    }

    public function replyRejected(string $reason): self
    {
        return $this->with(['replyError' => $reason]);
    }

    public function hasOutstandingRequest(): bool
    {
        return $this->requestedAt !== null && ($this->receivedAt === null || $this->requestedAt > $this->receivedAt);
    }

    public function needsRequest(DateTimeImmutable $now): bool
    {
        if ($this->followUpDueAt !== null && $now >= $this->followUpDueAt) {
            return true;
        }

        if ($this->hasOutstandingRequest()) {
            return self::secondsBetween($this->requestedAt, $now) >= Snapshots::FRESHNESS_SECONDS;
        }

        return $this->receivedAt === null || self::secondsBetween($this->receivedAt, $now) >= Snapshots::FRESHNESS_SECONDS;
    }

    public function needsRefresh(DateTimeImmutable $now, int $replyTimeoutSeconds): bool
    {
        return ! $this->hasOutstandingRequest() || $this->isUnanswered($now, $replyTimeoutSeconds) || $this->needsRequest($now);
    }

    public function statusAt(DateTimeImmutable $now, int $replyTimeoutSeconds): ServiceStatus
    {
        if ($this->missingDestination !== null && $this->hasOutstandingRequest()) {
            return ServiceStatus::ChannelMissing;
        }

        if ($this->hasOutstandingRequest()) {
            return $this->isUnanswered($now, $replyTimeoutSeconds)
                ? ServiceStatus::Unreachable
                : ServiceStatus::Waiting;
        }

        return $this->receivedAt !== null && self::secondsBetween($this->receivedAt, $now) < Snapshots::FRESHNESS_SECONDS
            ? ServiceStatus::Connected
            : ServiceStatus::Waiting;
    }

    public function ageAt(DateTimeImmutable $now): ?int
    {
        return $this->receivedAt === null ? null : self::secondsBetween($this->receivedAt, $now);
    }

    private function isUnanswered(DateTimeImmutable $now, int $replyTimeoutSeconds): bool
    {
        return $this->retrying || $this->requestFailure !== null || self::secondsBetween($this->requestedAt, $now) >= $replyTimeoutSeconds;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    private static function secondsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return $to->getTimestamp() - $from->getTimestamp();
    }
}
