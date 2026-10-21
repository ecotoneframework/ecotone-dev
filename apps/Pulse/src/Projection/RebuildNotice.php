<?php

declare(strict_types=1);

namespace App\Projection;

final readonly class RebuildNotice
{
    public const REQUESTED = 'requested';
    public const NOT_CONFIRMED = 'not_confirmed';
    public const UNKNOWN_PROJECTION = 'unknown_projection';
    public const SEND_FAILED = 'send_failed';

    private const CODES = [self::REQUESTED, self::NOT_CONFIRMED, self::UNKNOWN_PROJECTION, self::SEND_FAILED];

    private function __construct(public string $code, public string $projection)
    {
    }

    public static function of(string $code, string $projection): self
    {
        return new self($code, $projection);
    }

    public static function fromQuery(mixed $code, mixed $projection): ?self
    {
        return is_string($code) && in_array($code, self::CODES, true) && is_string($projection) && mb_strlen($projection) <= 255 ? new self($code, $projection) : null;
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return ['rebuild' => $this->code, 'projection' => $this->projection];
    }

    public function isError(): bool
    {
        return $this->code !== self::REQUESTED;
    }
}
