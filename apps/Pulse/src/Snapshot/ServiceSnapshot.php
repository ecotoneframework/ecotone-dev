<?php

declare(strict_types=1);

namespace App\Snapshot;

use DateTimeImmutable;

final readonly class ServiceSnapshot
{
    /**
     * @param array<string, mixed> $snapshot
     * @param list<string> $tenants
     * @param list<string> $unadvertisedTenants
     * @param array<string, array{digest: string, since: string}> $projectionMovements
     * @param array<string, array{ready: int, since: string}> $channelMovements
     */
    public function __construct(
        public string $service,
        public string $channel,
        public ServiceStatus $status,
        public ?DateTimeImmutable $lastSeenAt,
        public ?int $ageSeconds,
        public array $snapshot,
        public ?string $requestFailure,
        public ?string $replyError,
        public bool $followUpPending,
        public bool $retrying,
        public ?string $tenant,
        public ?string $tenantHeader,
        public array $tenants,
        public array $unadvertisedTenants,
        public array $projectionMovements,
        public int $deadLettersGrewBy,
        public array $channelMovements = [],
    ) {
    }

    public function scope(): string
    {
        return ($this->tenant === null ? ServiceScope::ofService($this->service) : ServiceScope::ofTenant($this->service, $this->tenant))->key();
    }

    public function isMultiTenant(): bool
    {
        return $this->tenantHeader !== null;
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

    public function failureCount(): ?int
    {
        $deadLetters = $this->snapshot['deadLetters'] ?? null;
        if (! is_array($deadLetters) || ! is_array($deadLetters['groups'] ?? null)) {
            return null;
        }

        return count($deadLetters['groups']);
    }
}
