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
        public ?string $replyError,
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

        return is_int($deadLetters['total'] ?? null) ? $deadLetters['total'] : null;
    }
}
