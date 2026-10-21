<?php

declare(strict_types=1);

namespace App\Projection;

final readonly class Rebuild
{
    public function __construct(
        public string $tracingId,
        public string $projection,
        public string $startedAt,
        public string $state,
        public int $batchesDone,
        public ?int $batchCount,
        public ?int $elapsedMs,
        public ?int $durationMs,
    ) {
    }

    /**
     * @param array<string, mixed> $tracking
     */
    public static function fromTracking(array $tracking): ?self
    {
        if (! is_string($tracking['tracingId'] ?? null) || ! is_string($tracking['reference'] ?? null) || ! in_array($tracking['state'] ?? null, ['running', 'done', 'failed'], true)) {
            return null;
        }

        return new self(
            $tracking['tracingId'],
            $tracking['reference'],
            is_string($tracking['startedAt'] ?? null) ? $tracking['startedAt'] : '',
            $tracking['state'],
            is_int($tracking['batchesDone'] ?? null) ? $tracking['batchesDone'] : 0,
            is_int($tracking['batchCount'] ?? null) ? $tracking['batchCount'] : null,
            is_int($tracking['elapsedMs'] ?? null) ? $tracking['elapsedMs'] : null,
            is_int($tracking['durationMs'] ?? null) ? $tracking['durationMs'] : null,
        );
    }

    public function isRunning(): bool
    {
        return $this->state === 'running';
    }

    public function percent(): int
    {
        return $this->batchCount === null || $this->batchCount === 0 ? 0 : intdiv(min($this->batchesDone, $this->batchCount) * 100, $this->batchCount);
    }

    public function elapsed(): ?string
    {
        return $this->elapsedMs === null ? null : self::spoken($this->elapsedMs);
    }

    public function duration(): ?string
    {
        return $this->durationMs === null ? null : self::spoken($this->durationMs);
    }

    private static function spoken(int $milliseconds): string
    {
        if ($milliseconds < 1000) {
            return $milliseconds . 'ms';
        }
        if ($milliseconds < 60_000) {
            return rtrim(rtrim(number_format($milliseconds / 1000, 1, '.', ''), '0'), '.') . 's';
        }
        $seconds = intdiv($milliseconds, 1000);
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm ' . $seconds % 60 . 's';
        }

        return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
    }
}
