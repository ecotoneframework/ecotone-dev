<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing\Pulse;

use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanEvent;
use Ecotone\Dbal\Tracing\TraceStore;
use Ecotone\Dbal\Tracing\TraceSummary;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSnapshot;
use JsonException;
use stdClass;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class PulseTraceQueries
{
    public const LIST_ROUTING_KEY = 'ecotone.pulse.traces.list';
    public const TRACE_ROUTING_KEY = 'ecotone.pulse.traces.trace';
    public const QUERY_ID_HEADER = 'ecotone_pulse_query_id';
    public const MAX_PER_PAGE = 25;
    private const LIST_EXAMPLE = '{"flowType": "create.ticket", "page": 1, "perPage": 25}';
    private const TRUNCATION_LIMIT_IN_BYTES = 4096;
    private const TRUNCATION_MARKER = "\n... [truncated]";

    public function __construct(private TraceStore $traceStore, private string $serviceName)
    {
    }

    public function list(string $payload, ?string $sourceService, ?string $queryId, PulseSnapshot $snapshot): string
    {
        $snapshot->authorizeRequestFrom($sourceService);
        $queryId = $this->queryIdOf($queryId);
        $query = $this->objectOf($payload, 'A Pulse trace list query payload must be a JSON object such as ' . self::LIST_EXAMPLE);
        $flowType = $query->flowType ?? null;
        if (! is_string($flowType) || $flowType === '') {
            throw InvalidArgumentException::create('A Pulse trace list query needs a `flowType`, but the payload had none. Send ' . self::LIST_EXAMPLE . ', taking the flow type from `tracing.flowTypes` of the Pulse snapshot.');
        }
        $page = $query->page ?? 1;
        if (! is_int($page) || $page < 1) {
            throw InvalidArgumentException::create('The `page` of a Pulse trace list query is `' . self::printable($page) . '`, but pages start at 1. Send ' . self::LIST_EXAMPLE . '.');
        }
        $perPage = $query->perPage ?? self::MAX_PER_PAGE;
        if (! is_int($perPage) || $perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw InvalidArgumentException::create('The `perPage` of a Pulse trace list query is `' . self::printable($perPage) . '`, but it must be a number from 1 to ' . self::MAX_PER_PAGE . '. Send a smaller `perPage`, or ask for the next `page`.');
        }

        $tracePage = $this->traceStore->tracesOf($flowType, $page, $perPage);

        return json_encode([
            'queryId' => $queryId,
            'flowType' => $flowType,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $tracePage->total,
            'traces' => array_map(fn (TraceSummary $trace): array => [
                'traceId' => $trace->traceId,
                'startedAt' => PulseTime::format($trace->startedAtInMicroseconds),
                'durationMs' => $trace->durationMs,
                'status' => $trace->status,
                'rootEndpoint' => $trace->rootEndpoint,
            ], $tracePage->traces),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function trace(string $payload, ?string $sourceService, ?string $queryId, PulseSnapshot $snapshot): string
    {
        $snapshot->authorizeRequestFrom($sourceService);
        $queryId = $this->queryIdOf($queryId);
        $query = $this->objectOf($payload, 'A Pulse trace query payload must be a JSON object such as {"traceId": "0af7651916cd43dd8448eb211c80319c"}');
        $traceId = $query->traceId ?? null;
        if (! is_string($traceId) || $traceId === '') {
            throw InvalidArgumentException::create('A Pulse trace query needs a `traceId`, but the payload had none. Send {"traceId": "0af7651916cd43dd8448eb211c80319c"}, taking the trace id from a trace list or from the trace id an application logged.');
        }

        return json_encode([
            'queryId' => $queryId,
            'traceId' => $traceId,
            'spans' => array_map($this->spanToArray(...), self::inTreeOrder($this->traceStore->stepsOf($traceId))),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function queryIdOf(?string $queryId): string
    {
        if ($queryId === null || $queryId === '') {
            throw InvalidArgumentException::create("Service {$this->serviceName} was asked by Pulse for traces without the query id in header `" . self::QUERY_ID_HEADER . "`, so Pulse could not match the result to its question. Send the request with metadata: ['" . self::QUERY_ID_HEADER . "' => '<unique id>'] on DistributedBus::sendMessage().");
        }

        return $queryId;
    }

    private function objectOf(string $payload, string $expectation): stdClass
    {
        try {
            $query = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $query = null;
        }
        if (! $query instanceof stdClass) {
            throw InvalidArgumentException::create($expectation . ", but `{$payload}` was sent.");
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function spanToArray(Span $span): array
    {
        return [
            'spanId' => $span->spanId,
            'parentSpanId' => $span->parentSpanId,
            'name' => self::truncated($span->name),
            'kind' => $span->kind->value,
            'status' => $span->status->value,
            'statusDescription' => $span->statusDescription === null ? null : self::truncated($span->statusDescription),
            'startedAt' => PulseTime::format($span->startedAt->unixTime()->inMicroseconds()),
            'endedAt' => PulseTime::format($span->endedAt->unixTime()->inMicroseconds()),
            'attributes' => (object) self::boundedValues($span->attributes),
            'events' => array_map(fn (SpanEvent $event): array => [
                'name' => self::truncated($event->name),
                'at' => PulseTime::format($event->at->unixTime()->inMicroseconds()),
                'attributes' => (object) self::boundedValues($event->attributes),
            ], $span->events),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function boundedValues(array $values): array
    {
        return array_map(fn (mixed $value): mixed => is_string($value) ? self::truncated($value) : (is_array($value) ? self::boundedValues($value) : $value), $values);
    }

    /**
     * @param Span[] $spans
     * @return Span[]
     */
    private static function inTreeOrder(array $spans): array
    {
        $spanIds = array_fill_keys(array_map(fn (Span $span): string => $span->spanId, $spans), true);
        $childrenOf = [];
        foreach ($spans as $span) {
            $parent = $span->parentSpanId !== null && isset($spanIds[$span->parentSpanId]) ? $span->parentSpanId : '';
            $childrenOf[$parent][] = $span;
        }
        foreach ($childrenOf as &$children) {
            usort($children, fn (Span $first, Span $second): int => [$first->startedAt->unixTime()->inMicroseconds(), $first->name, $first->spanId] <=> [$second->startedAt->unixTime()->inMicroseconds(), $second->name, $second->spanId]);
        }
        unset($children);

        $ordered = [];
        $visit = function (string $parent) use (&$visit, &$ordered, $childrenOf): void {
            foreach ($childrenOf[$parent] ?? [] as $child) {
                $ordered[] = $child;
                $visit($child->spanId);
            }
        };
        $visit('');

        return $ordered;
    }

    private static function truncated(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        if (strlen($text) <= self::TRUNCATION_LIMIT_IN_BYTES) {
            return $text;
        }

        return mb_strcut($text, 0, self::TRUNCATION_LIMIT_IN_BYTES - strlen(self::TRUNCATION_MARKER), 'UTF-8') . self::TRUNCATION_MARKER;
    }

    private static function printable(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
