<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Pulse\Tracking;

use DateTimeImmutable;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\GatheredReleaseReason;
use Ecotone\Dbal\Recoverability\DeadLetterStore;
use Ecotone\Dbal\Recoverability\Pulse\PulseDeadLetterScan;
use Ecotone\Dbal\Tracing\Pulse\PulseTime;
use Ecotone\Messaging\Handler\Gathering\GatheringHeaders;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSnapshot;
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

    private const OPERATIONS = [Tracking::PROJECTION_REBUILD_TYPE => 'rebuild', Tracking::PROJECTION_BACKFILL_TYPE => 'backfill'];

    public function __construct(
        private TrackingStore $trackings,
        private ProjectionRunGroups $runGroups,
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
        $since = $this->sinceOf($query);
        $category = $this->optionalText($query, 'category');
        $type = $this->optionalText($query, 'type');
        $reference = $this->optionalText($query, 'reference');

        $groups = [];
        foreach ($this->runGroups->listRunsOpenedSince(intdiv($since, 1000)) as $group) {
            $groups[$group['groupId']] = $group;
        }
        $runs = array_map(fn (Tracking $tracking): array => $this->runOf($tracking, $groups), $this->trackings->startedSince($since, $category, $type, $reference, $limit + 1));
        $matchedGroupIds = array_flip(array_filter(array_map(static fn (array $run): ?string => $run['group']['groupId'] ?? null, $runs)));
        foreach (array_diff_key($groups, $matchedGroupIds) as $group) {
            $run = $this->consoleRunOf($group);
            if ($this->matches(run: $run, since: $since, category: $category, type: $type, reference: $reference)) {
                $runs[] = $run;
            }
        }
        usort($runs, static fn (array $first, array $second): int => $second['startedAt'] <=> $first['startedAt']);
        $listed = array_slice($runs, 0, $limit);
        $deadLetters = $this->deadLettersOf(array_values(array_filter(array_map(static fn (array $run): ?array => $run['group'] !== null && $run['group']['state'] === 'open' ? $run['group'] : null, $listed))));
        $now = $this->clock->now()->unixTime()->inMicroseconds();

        return json_encode([
            'queryId' => $queryId,
            'cap' => self::CAP,
            'truncated' => count($runs) > $limit,
            'trackings' => array_map(fn (array $run): array => $this->described(run: $run, deadLetters: $deadLetters[$run['group']['groupId'] ?? ''] ?? ['failed' => [], 'waiting' => []], now: $now), $listed),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, array{projectionName: string, operation: string, groupId: string, groupKey: ?string, state: string, expectedSize: ?int, recorded: int, missingPartNumbers: list<int>, openedAt: int, closedAt: ?int, releaseReason: ?string}> $groups
     * @return array{tracingId: ?string, category: string, type: string, reference: string, tenant: ?string, startedAt: int, startedFrom: string, asynchronous: bool, group: ?array}
     */
    private function runOf(Tracking $tracking, array $groups): array
    {
        $operation = self::OPERATIONS[$tracking->type] ?? null;
        $asynchronous = $operation !== null && $this->runGroups->runsAsynchronously($tracking->reference, $operation);
        $matching = $asynchronous
            ? array_filter($groups, static fn (array $group): bool => $group['groupKey'] === $tracking->tracingId && $group['projectionName'] === $tracking->reference && $group['operation'] === $operation)
            : [];

        return [
            'tracingId' => $tracking->tracingId,
            'category' => $tracking->category,
            'type' => $tracking->type,
            'reference' => $tracking->reference,
            'tenant' => $tracking->tenant,
            'startedAt' => $tracking->startedAtInMicroseconds,
            'startedFrom' => 'pulse',
            'asynchronous' => $asynchronous,
            'group' => $matching === [] ? null : array_values($matching)[0],
        ];
    }

    /**
     * @param array{projectionName: string, operation: string, groupId: string, groupKey: ?string, state: string, expectedSize: ?int, recorded: int, missingPartNumbers: list<int>, openedAt: int, closedAt: ?int, releaseReason: ?string} $group
     * @return array{tracingId: ?string, category: string, type: string, reference: string, tenant: ?string, startedAt: int, startedFrom: string, asynchronous: bool, group: ?array}
     */
    private function consoleRunOf(array $group): array
    {
        return [
            'tracingId' => $group['groupKey'],
            'category' => Tracking::PULSE_CATEGORY,
            'type' => array_search($group['operation'], self::OPERATIONS, true),
            'reference' => $group['projectionName'],
            'tenant' => $this->trackings->activeTenant(),
            'startedAt' => $group['openedAt'] * 1000,
            'startedFrom' => 'console',
            'asynchronous' => true,
            'group' => $group,
        ];
    }

    private function matches(array $run, int $since, ?string $category, ?string $type, ?string $reference): bool
    {
        return $run['startedAt'] >= $since
            && ($category === null || $category === $run['category'])
            && ($type === null || $type === $run['type'])
            && ($reference === null || $reference === $run['reference']);
    }

    /**
     * @param list<array{projectionName: string, operation: string, groupId: string, groupKey: ?string, state: string, expectedSize: ?int, recorded: int, missingPartNumbers: list<int>, openedAt: int, closedAt: ?int, releaseReason: ?string}> $openGroups
     * @return array<string, array{failed: list<array{batch: int, deadLetterMessageId: string}>, waiting: list<array{batch: int, deadLetterMessageId: string}>}>
     */
    private function deadLettersOf(array $openGroups): array
    {
        $unaccounted = [];
        foreach ($openGroups as $group) {
            if ($group['missingPartNumbers'] !== []) {
                $unaccounted[$group['groupId']] = array_flip($group['missingPartNumbers']);
            }
        }
        $found = [];
        $scanned = 0;
        foreach ($unaccounted === [] ? [] : PulseDeadLetterScan::newestFirst($this->deadLetters) as $entry) {
            $failure = $entry->toArray();
            $groupId = $failure['headers'][GatheringHeaders::GROUP_ID] ?? null;
            $batch = (int) ($failure['headers'][GatheringHeaders::PART_NUMBER] ?? 0);
            if ($groupId !== null && isset($unaccounted[$groupId][$batch])) {
                $kind = array_key_exists(GatheringHeaders::NO_RESULT, $failure['headers']) ? 'waiting' : 'failed';
                $found[$groupId][$kind][$batch] = ['batch' => $batch, 'deadLetterMessageId' => $failure['messageId']];
                unset($unaccounted[$groupId][$batch]);
                if ($unaccounted[$groupId] === []) {
                    unset($unaccounted[$groupId]);
                }
            }
            if ($unaccounted === [] || ++$scanned >= self::DEAD_LETTERS_SCANNED) {
                break;
            }
        }

        return array_map(static fn (array $kinds): array => ['failed' => self::sortedByBatch($kinds['failed'] ?? []), 'waiting' => self::sortedByBatch($kinds['waiting'] ?? [])], $found);
    }

    /**
     * @param array<int, array{batch: int, deadLetterMessageId: string}> $batches
     * @return list<array{batch: int, deadLetterMessageId: string}>
     */
    private static function sortedByBatch(array $batches): array
    {
        ksort($batches);

        return array_values($batches);
    }

    /**
     * @param array{tracingId: ?string, category: string, type: string, reference: string, tenant: ?string, startedAt: int, startedFrom: string, asynchronous: bool, group: ?array} $run
     * @param array{failed: list<array{batch: int, deadLetterMessageId: string}>, waiting: list<array{batch: int, deadLetterMessageId: string}>} $deadLetters
     */
    private function described(array $run, array $deadLetters, int $now): array
    {
        $group = $run['group'];
        $state = match (true) {
            ! $run['asynchronous'], $group === null => 'done',
            $group['state'] === 'discarded' => 'discarded',
            $group['state'] !== 'open' && $group['releaseReason'] === GatheredReleaseReason::ALL_PARTS_ACCOUNTED_FOR->value => 'done',
            $group['state'] !== 'open' => 'released',
            $deadLetters['failed'] !== [] => 'failed',
            default => 'running',
        };
        $described = [
            'tracingId' => $run['tracingId'],
            'category' => $run['category'],
            'type' => $run['type'],
            'reference' => $run['reference'],
            'tenant' => $run['tenant'],
            'startedAt' => PulseTime::format($run['startedAt']),
            'startedFrom' => $run['startedFrom'],
            'state' => $state,
            'batchesDone' => $group['recorded'] ?? 0,
            'batchCount' => $group['expectedSize'] ?? null,
            'missingBatches' => $group['missingPartNumbers'] ?? [],
            'failedBatches' => $deadLetters['failed'],
            'batchesWaitingToBeRecorded' => $deadLetters['waiting'],
            ...($group === null ? [] : ['groupId' => $group['groupId']]),
        ];

        return match ($state) {
            'done', 'discarded', 'released' => [...$described, 'durationMs' => ($group['closedAt'] ?? null) === null ? null : max(0, $group['closedAt'] - intdiv($run['startedAt'], 1000))],
            'failed' => [...$described, 'deadLetterMessageId' => $deadLetters['failed'][0]['deadLetterMessageId'], 'elapsedMs' => intdiv(max(0, $now - $run['startedAt']), 1000)],
            default => [...$described, 'elapsedMs' => intdiv(max(0, $now - $run['startedAt']), 1000)],
        };
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
