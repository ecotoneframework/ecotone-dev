<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

/**
 * licence Apache-2.0
 */
final class ExecutionHint
{
    public const CHOSEN_BY = 'chosen by';
    public const RETURNS = 'returns';
    public const OUTCOME = 'outcome';
    public const FAILURE = 'failure';
    public const FOUND = 'found';
    public const ROUTING_SLIP = 'routing slip';
    public const KEPT = 'kept';
    public const HEADERS_ADDED = 'headers added';
    public const HEADERS_REMOVED = 'headers removed';
    public const HEADERS_CHANGED = 'headers changed';
    public const HEADERS_READ = 'headers read';
    public const PAYLOAD = 'payload';
    public const SENT_BY = 'sent by';
    public const TAKEN_BY = 'taken by';
    public const STOPPED = 'stopped';
    public const COLLECTED = 'collected';
    public const RUNS_IN = 'runs in';
    public const STARTED_IN = 'started in';
    public const STARTS = 'starts';
    public const RANGE = 'range';
    public const IDENTIFIERS = 'identifiers';
    public const PROPAGATING = 'propagating';
    public const RECEIVED_WITH = 'received with';
    public const RETRY = 'retry';
    public const GROUP = 'group';
    public const SERVICE = 'service';

    private function __construct(public readonly string $key, public readonly string $value, public readonly bool $shownInLines)
    {
    }

    public static function shownInLines(string $key, string $value): self
    {
        return new self(key: $key, value: $value, shownInLines: true);
    }

    public static function detail(string $key, string $value): self
    {
        return new self(key: $key, value: $value, shownInLines: false);
    }

    /**
     * @param list<self> $hints
     */
    public static function encoded(array $hints): string
    {
        return self::json(array_map(fn (self $hint): array => [$hint->key, $hint->value, $hint->shownInLines], $hints));
    }

    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
