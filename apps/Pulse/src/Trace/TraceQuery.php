<?php

declare(strict_types=1);

namespace App\Trace;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class TraceQuery
{
    public const LIST_ROUTING_KEY = 'ecotone.pulse.traces.list';
    public const TRACE_ROUTING_KEY = 'ecotone.pulse.traces.trace';
    public const TRACKINGS_ROUTING_KEY = 'ecotone.pulse.trackings.list';
    public const TRACKINGS_FRESHNESS_SECONDS = 2;
    public const PER_PAGE = 25;

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(public string $routingKey, public array $payload, public int $freshnessSeconds = QueryRecord::FRESHNESS_SECONDS, private ?array $identity = null)
    {
    }

    public static function list(string $flowType, int $page): self
    {
        return new self(self::LIST_ROUTING_KEY, ['flowType' => $flowType, 'page' => $page, 'perPage' => self::PER_PAGE]);
    }

    public static function projectionOperations(DateTimeImmutable $since, int $days): self
    {
        return new self(self::TRACKINGS_ROUTING_KEY, ['since' => $since->format(DateTimeInterface::ATOM), 'category' => 'ecotone-pulse'], self::TRACKINGS_FRESHNESS_SECONDS, ['days' => $days]);
    }

    public static function trace(string $traceId): self
    {
        return new self(self::TRACE_ROUTING_KEY, ['traceId' => $traceId]);
    }

    public function key(): string
    {
        return sha1($this->routingKey . json_encode($this->identity ?? $this->payload, JSON_THROW_ON_ERROR));
    }

    public function json(): string
    {
        return json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
