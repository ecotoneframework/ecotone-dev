<?php

declare(strict_types=1);

namespace App\Snapshot;

final readonly class RequestResult
{
    private function __construct(
        public bool $sent,
        public bool $failed,
        public ?string $tracingId,
        public ?string $destination,
    ) {
    }

    public static function notNeeded(): self
    {
        return new self(false, false, null, null);
    }

    public static function sent(string $tracingId, string $destination): self
    {
        return new self(true, false, $tracingId, $destination);
    }

    public static function failed(): self
    {
        return new self(false, true, null, null);
    }
}
