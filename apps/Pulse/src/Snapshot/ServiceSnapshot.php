<?php

declare(strict_types=1);

namespace App\Snapshot;

use DateTimeImmutable;

final readonly class ServiceSnapshot
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public string $service,
        public ServiceStatus $status,
        public ?DateTimeImmutable $lastSeenAt,
        public ?int $ageSeconds,
        public array $snapshot,
        public ?string $requestFailure,
    ) {
    }

    public function hasSnapshot(): bool
    {
        return $this->lastSeenAt !== null;
    }

    public function ecotoneVersion(): ?string
    {
        $version = $this->snapshot['overview']['ecotoneVersion'] ?? null;

        return is_string($version) ? $version : null;
    }

    public function deadLetterTotal(): ?int
    {
        $deadLetters = $this->snapshot['deadLetters'] ?? null;
        if (! is_array($deadLetters)) {
            return null;
        }
        if (isset($deadLetters['total']) && is_int($deadLetters['total'])) {
            return $deadLetters['total'];
        }
        if (isset($deadLetters['messages']) && is_array($deadLetters['messages'])) {
            return count($deadLetters['messages']);
        }

        return null;
    }
}
