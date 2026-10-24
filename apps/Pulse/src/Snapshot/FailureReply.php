<?php

declare(strict_types=1);

namespace App\Snapshot;

use DateTimeImmutable;
use Throwable;

final readonly class FailureReply
{
    private function __construct(
        public string $requestKind,
        public string $message,
        public DateTimeImmutable $failedAt,
        public ?string $queryId,
    ) {
    }

    /**
     * @param array<string, mixed> $decoded
     */
    public static function isOne(array $decoded): bool
    {
        return is_string($decoded['requestKind'] ?? null) && is_string($decoded['message'] ?? null) && is_string($decoded['failedAt'] ?? null);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    public static function of(array $decoded): self
    {
        try {
            $failedAt = new DateTimeImmutable($decoded['failedAt']);
        } catch (Throwable) {
            $failedAt = new DateTimeImmutable();
        }

        return new self($decoded['requestKind'], $decoded['message'], $failedAt, is_string($decoded['queryId'] ?? null) ? $decoded['queryId'] : null);
    }
}
