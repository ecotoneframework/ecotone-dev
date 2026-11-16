<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

/**
 * licence Apache-2.0
 */
final class ExecutionHint
{
    public const CHOSEN_BY = 'chosen by';
    public const RESULT = 'result';
    public const ROUTING_SLIP = 'routing slip';
    public const HEADERS_ADDED = 'headers added';
    public const HEADERS_REMOVED = 'headers removed';
    public const HEADERS_CHANGED = 'headers changed';
    public const PAYLOAD = 'payload';
    public const SENT_BY = 'sent by';
    public const TAKEN_BY = 'taken by';
    public const STOPPED = 'stopped';
    public const RUNS = 'runs';
    public const READ_FROM = 'read from';
    public const PROPAGATING = 'propagating';
    public const RECEIVED_WITH = 'received with';
    public const RETRY = 'retry';
    public const GROUP = 'group';
    public const KEPT = 'kept';
    public const BY = 'by';
    public const KEYS = [
        self::CHOSEN_BY, self::RESULT, self::ROUTING_SLIP, self::HEADERS_ADDED, self::HEADERS_REMOVED, self::HEADERS_CHANGED, self::PAYLOAD,
        self::SENT_BY, self::TAKEN_BY, self::STOPPED, self::RUNS, self::READ_FROM, self::PROPAGATING, self::RECEIVED_WITH, self::RETRY, self::GROUP, self::KEPT, self::BY,
    ];

    private function __construct(public readonly string $key, public readonly string $value, public readonly bool $critical)
    {
    }

    public static function critical(string $key, string $value): self
    {
        return new self($key, $value, true);
    }

    public static function detail(string $key, string $value): self
    {
        return new self($key, $value, false);
    }

    /**
     * @param list<self> $hints
     */
    public static function encoded(array $hints): string
    {
        return json_encode(array_map(fn (self $hint): array => [$hint->key, $hint->value, $hint->critical], $hints), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
