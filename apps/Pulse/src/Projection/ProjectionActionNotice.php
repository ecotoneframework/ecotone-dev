<?php

declare(strict_types=1);

namespace App\Projection;

final readonly class ProjectionActionNotice
{
    public const REQUESTED = 'requested';
    public const NOT_CONFIRMED = 'not_confirmed';
    public const UNKNOWN_PROJECTION = 'unknown_projection';
    public const SEND_FAILED = 'send_failed';

    private const CODES = [self::REQUESTED, self::NOT_CONFIRMED, self::UNKNOWN_PROJECTION, self::SEND_FAILED];
    private const QUERY_KEYS = [Rebuild::REBUILD => 'rebuild', Rebuild::BACKFILL => 'backfill'];

    private function __construct(public string $action, public string $code, public string $projection)
    {
    }

    public static function of(string $action, string $code, string $projection): self
    {
        return new self($action, $code, $projection);
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): ?self
    {
        $projection = $query['projection'] ?? null;
        if (! is_string($projection) || mb_strlen($projection) > 255) {
            return null;
        }
        foreach (self::QUERY_KEYS as $action => $key) {
            if (is_string($query[$key] ?? null) && in_array($query[$key], self::CODES, true)) {
                return new self($action, $query[$key], $projection);
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return [self::QUERY_KEYS[$this->action] => $this->code, 'projection' => $this->projection];
    }

    public function isError(): bool
    {
        return $this->code !== self::REQUESTED;
    }

    public function noun(): string
    {
        return match ($this->action) {
            Rebuild::BACKFILL => 'backfill',
            default => 'rebuild',
        };
    }
}
