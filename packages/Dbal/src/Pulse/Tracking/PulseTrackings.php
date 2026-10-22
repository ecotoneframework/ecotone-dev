<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Pulse\Tracking;

use DateTimeImmutable;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanStatus;
use Ecotone\Dbal\Recoverability\DeadLetterStore;
use Ecotone\Dbal\Recoverability\Pulse\PulseDeadLetterScan;
use Ecotone\Dbal\Tracing\Pulse\PulseTime;
use Ecotone\Dbal\Tracing\TraceStore;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSnapshot;
use Ecotone\Projecting\ProjectingHeaders;
use Exception;
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
final class PulseTrackings
{
    public const ROUTING_KEY = 'ecotone.pulse.trackings.list';
    public const QUERY_ID_HEADER = 'ecotone_pulse_query_id';
    public const CAP = 100;
    public const DEAD_LETTERS_SCANNED = 2000;
    private const EXAMPLE = '{"since": "2026-10-14T12:00:00+00:00", "category": "ecotone-pulse", "type": "projection-rebuild", "reference": "orders", "limit": 100}';

    public function __construct(
        private TrackingStore $trackings,
        private TraceStore $traces,
        private DeadLetterStore $deadLetters,
        private EcotoneClockInterface $clock,
        private string $serviceName,
    ) {
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function list(string $payload, ?string $sourceService, array $headers, ?string $queryId, PulseSnapshot $snapshot): string
    {
        $snapshot->authorizeRequestFrom($sourceService);
        $snapshot->tenantOfScopedRequest($headers);
        $queryId = $this->queryIdOf($queryId);
        $query = $this->queryOf($payload);
        $limit = $this->limitOf($query);

        $found = $this->trackings->startedSince($this->sinceOf($query), $this->optionalText($query, 'category'), $this->optionalText($query, 'type'), $this->optionalText($query, 'reference'), $limit + 1);
        $listed = array_slice($found, 0, $limit);
        $now = $this->clock->now()->unixTime()->inMicroseconds();
        $progress = [];
        foreach ($listed as $tracking) {
            $progress[$tracking->tracingId] = $this->progressOf($tracking, $this->traces->stepsOf($tracking->tracingId));
        }
        $deadLetters = $this->deadLetterMessageIdsOf(array_keys(array_filter($progress, fn (array $one): bool => $one['state'] !== 'done')));

        return json_encode([
            'queryId' => $queryId,
            'cap' => self::CAP,
            'truncated' => count($found) > $limit,
            'trackings' => array_map(fn (Tracking $tracking): array => $this->described($tracking, $progress[$tracking->tracingId], $deadLetters[$tracking->tracingId] ?? null, $now), $listed),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param Span[] $spans
     * @return array{state: string, batchesDone: int, batchCount: int|null, finishedAt: int|null}
     */
    private function progressOf(Tracking $tracking, array $spans): array
    {
        if ($tracking->type === Tracking::PROJECTION_INITIALIZATION_TYPE) {
            $endings = array_map(fn (Span $span): int => $span->endedAt->unixTime()->inMicroseconds(), $spans);

            return ['state' => 'done', 'batchesDone' => 0, 'batchCount' => null, 'finishedAt' => $endings === [] ? null : max($endings)];
        }
        $batches = [];
        $batchCount = null;
        $finishedAt = null;
        $failed = false;
        foreach ($spans as $span) {
            $failed = $failed || $span->status === SpanStatus::Error;
            if (! isset($span->attributes[ProjectingHeaders::REBUILD_BATCH_NUMBER]) || ($span->attributes[SpanAttribute::MESSAGING_OPERATION_TYPE] ?? null) !== SpanAttribute::OPERATION_PROCESS) {
                continue;
            }
            $batchNumber = (int) $span->attributes[ProjectingHeaders::REBUILD_BATCH_NUMBER];
            if ($batchNumber > 0) {
                $batches[$batchNumber] = true;
            }
            $batchCount = (int) $span->attributes[ProjectingHeaders::REBUILD_BATCH_COUNT];
            $finishedAt = max($finishedAt ?? 0, $span->endedAt->unixTime()->inMicroseconds());
        }
        $state = $batchCount !== null && count($batches) >= $batchCount ? 'done' : ($failed ? 'failed' : 'running');

        return ['state' => $state, 'batchesDone' => count($batches), 'batchCount' => $batchCount, 'finishedAt' => $finishedAt];
    }

    /**
     * @param string[] $tracingIds
     * @return array<string, string> dead letter message id per tracing id
     */
    private function deadLetterMessageIdsOf(array $tracingIds): array
    {
        $wanted = array_flip($tracingIds);
        $found = [];
        if ($wanted === []) {
            return $found;
        }
        $scanned = 0;
        foreach (PulseDeadLetterScan::newestFirst($this->deadLetters) as $entry) {
            $failure = $entry->toArray();
            if (isset($wanted[$failure['traceId'] ?? ''])) {
                $found[$failure['traceId']] ??= $failure['messageId'];
            }
            if (count($found) === count($wanted) || ++$scanned >= self::DEAD_LETTERS_SCANNED) {
                break;
            }
        }

        return $found;
    }

    /**
     * @param array{state: string, batchesDone: int, batchCount: int|null, finishedAt: int|null} $progress
     */
    private function described(Tracking $tracking, array $progress, ?string $deadLetterMessageId, int $now): array
    {
        $state = $deadLetterMessageId !== null && $progress['state'] === 'running' ? 'failed' : $progress['state'];
        $described = [
            'tracingId' => $tracking->tracingId,
            'category' => $tracking->category,
            'type' => $tracking->type,
            'reference' => $tracking->reference,
            'tenant' => $tracking->tenant,
            'startedAt' => PulseTime::format($tracking->startedAtInMicroseconds),
            'state' => $state,
            'batchesDone' => $progress['batchesDone'],
            'batchCount' => $progress['batchCount'],
        ];

        return match ($state) {
            'done' => [...$described, 'durationMs' => $this->durationOf($tracking, $progress['finishedAt'], $now)],
            'failed' => [...$described, 'deadLetterMessageId' => $deadLetterMessageId],
            default => [...$described, 'elapsedMs' => intdiv(max(0, $now - $tracking->startedAtInMicroseconds), 1000)],
        };
    }

    private function durationOf(Tracking $tracking, ?int $finishedAt, int $now): ?int
    {
        if ($finishedAt === null) {
            return $tracking->type === Tracking::PROJECTION_INITIALIZATION_TYPE ? null : intdiv(max(0, $now - $tracking->startedAtInMicroseconds), 1000);
        }

        return intdiv(max(0, $finishedAt - $tracking->startedAtInMicroseconds), 1000);
    }

    private function queryIdOf(?string $queryId): string
    {
        if ($queryId === null || $queryId === '') {
            throw InvalidArgumentException::create("Service {$this->serviceName} was asked by Pulse for trackings without the query id in header `" . self::QUERY_ID_HEADER . "`, so Pulse could not match the result to its question. Send the request with metadata: ['" . self::QUERY_ID_HEADER . "' => '<unique id>'] on DistributedBus::sendMessage().");
        }

        return $queryId;
    }

    private function queryOf(string $payload): stdClass
    {
        try {
            $query = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $query = null;
        }
        if (! $query instanceof stdClass) {
            throw InvalidArgumentException::create('A Pulse trackings query payload must be a JSON object such as ' . self::EXAMPLE . ", but `{$payload}` was sent.");
        }

        return $query;
    }

    private function sinceOf(stdClass $query): int
    {
        $since = $query->since ?? null;
        try {
            if (is_string($since)) {
                return $this->microsecondsOf(new DateTimeImmutable($since));
            }
        } catch (Exception) {
        }

        throw InvalidArgumentException::create('A Pulse trackings query needs `since`, an ISO 8601 time, so the query stays bounded, but the payload had ' . ($since === null ? 'none' : '`' . (is_scalar($since) ? $since : json_encode($since)) . '`') . '. Send ' . self::EXAMPLE . ', with `since` as far back as PULSE_TRACKING_DAYS reaches.');
    }

    private function microsecondsOf(DateTimeImmutable $time): int
    {
        return (int) $time->format('U') * 1_000_000 + (int) $time->format('u');
    }

    private function limitOf(stdClass $query): int
    {
        $limit = $query->limit ?? self::CAP;
        if (! is_int($limit) || $limit < 1 || $limit > self::CAP) {
            throw InvalidArgumentException::create('The `limit` of a Pulse trackings query is `' . (is_scalar($limit) ? $limit : json_encode($limit)) . '`, but it must be a number from 1 to ' . self::CAP . '. Send a smaller `limit`, or narrow the query with `since`, `type` or `reference`.');
        }

        return $limit;
    }

    private function optionalText(stdClass $query, string $field): ?string
    {
        $value = $query->{$field} ?? null;
        if ($value !== null && ! is_string($value)) {
            throw InvalidArgumentException::create("The `{$field}` of a Pulse trackings query must be text, but `" . json_encode($value) . '` was sent. Send ' . self::EXAMPLE . '.');
        }

        return $value;
    }
}
