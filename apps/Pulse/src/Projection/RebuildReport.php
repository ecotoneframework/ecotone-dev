<?php

declare(strict_types=1);

namespace App\Projection;

final readonly class RebuildReport
{
    /**
     * @param list<Rebuild> $rebuilds newest first
     */
    public function __construct(
        public array $rebuilds,
        public bool $waiting,
        public bool $notAnswering,
        public bool $truncated,
        public ?int $cap,
    ) {
    }

    public static function none(): self
    {
        return new self([], false, false, false, null);
    }

    public static function unavailable(): self
    {
        return new self([], false, true, false, null);
    }

    public function latestOf(string $projection): ?Rebuild
    {
        foreach ($this->rebuilds as $rebuild) {
            if ($rebuild->projection === $projection) {
                return $rebuild;
            }
        }

        return null;
    }

    public function isRunning(string $projection): bool
    {
        return $this->latestOf($projection)?->isRunning() === true;
    }

    public function anyRunning(): bool
    {
        foreach ($this->rebuilds as $rebuild) {
            if ($rebuild->isRunning()) {
                return true;
            }
        }

        return false;
    }
}
