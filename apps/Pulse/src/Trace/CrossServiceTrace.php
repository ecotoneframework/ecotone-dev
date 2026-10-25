<?php

declare(strict_types=1);

namespace App\Trace;

use DateTimeImmutable;
use Exception;

final readonly class CrossServiceTrace
{
    public const MAX_ROWS = 300;
    private const ENDPOINT = 'ecotone.endpoint.id';
    private const CHANNEL = 'ecotone.channel.name';
    private const MESSAGE_ID = 'id';

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $services
     * @param array{channel: ?string, label: string}|null $longestQueueWait
     * @param array<string, mixed>|null $firstFailure
     * @param list<string> $pendingChannels
     */
    private function __construct(
        public array $rows,
        public int $totalRows,
        public string $status,
        public string $durationLabel,
        public ?string $startedAt,
        public int $totalMicro = 0,
        public array $services = [],
        public int $failedCount = 0,
        public ?array $longestQueueWait = null,
        public ?array $firstFailure = null,
        public array $pendingChannels = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->totalRows === 0;
    }

    public function omittedRows(): int
    {
        return $this->totalRows - count($this->rows);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $spansByService
     * @param array<string, array<string, array<string, mixed>>> $deadLettersByService
     */
    public static function merge(array $spansByService, array $deadLettersByService): self
    {
        $spans = [];
        foreach ($spansByService as $service => $serviceSpans) {
            foreach ($serviceSpans as $span) {
                if (is_array($span) && is_string($span['spanId'] ?? null)) {
                    $spans[] = self::normalised($service, $span);
                }
            }
        }
        if ($spans === []) {
            return new self([], 0, 'empty', '0 ms', null);
        }
        $spans = self::withTimesOfInvalidSpansAtTheStart($spans);

        $indexOf = [];
        foreach ($spans as $index => $span) {
            $indexOf[$span['spanId']][] = $index;
        }
        $parentOf = [];
        $childrenOf = [];
        foreach ($spans as $index => $span) {
            $parent = self::parentIndexOf($span, $spans, $indexOf);
            $parentOf[$index] = $parent;
            $childrenOf[$parent ?? -1][] = $index;
        }
        foreach ($childrenOf as &$children) {
            usort($children, fn (int $first, int $second): int => [$spans[$first]['startMicro'], $spans[$first]['name'], $spans[$first]['spanId']] <=> [$spans[$second]['startMicro'], $spans[$second]['name'], $spans[$second]['spanId']]);
        }
        unset($children);

        $flowStart = min(array_column($spans, 'startMicro'));
        $flowEnd = max(array_column($spans, 'endMicro'));
        $rows = [];
        $visited = [];
        $visit = function (int $index, int $depth) use (&$visit, &$rows, &$visited, $spans, $childrenOf, $parentOf, $flowStart, $deadLettersByService): void {
            if (isset($visited[$index])) {
                return;
            }
            $visited[$index] = true;
            $rows[] = self::rowOf($spans[$index], $depth, $parentOf[$index] === null ? null : $spans[$parentOf[$index]], array_map(fn (int $child): array => $spans[$child], $childrenOf[$index] ?? []), $flowStart, $deadLettersByService);
            foreach ($childrenOf[$index] ?? [] as $child) {
                $visit($child, $depth + 1);
            }
        };
        foreach ($childrenOf[-1] ?? [] as $root) {
            $visit($root, 0);
        }

        $status = match (true) {
            in_array(true, array_column($rows, 'failed'), true) => 'failed',
            in_array(true, array_column($rows, 'pending'), true) => 'pending',
            default => 'completed',
        };

        $services = array_values(array_unique(array_column($rows, 'service')));
        $rows = self::collapsible($rows, $services);
        $failures = array_values(array_filter($rows, fn (array $row): bool => $row['failed']));
        $waits = array_filter($rows, fn (array $row): bool => $row['waitMicro'] > 0);
        usort($waits, fn (array $first, array $second): int => $second['waitMicro'] <=> $first['waitMicro']);
        $pendingChannels = array_values(array_unique(array_filter(array_column(array_filter($rows, fn (array $row): bool => $row['pending']), 'channel'))));

        return new self(
            array_slice($rows, 0, self::MAX_ROWS),
            count($rows),
            $status,
            self::milliseconds($flowEnd - $flowStart),
            self::time($flowStart),
            $flowEnd - $flowStart,
            $services,
            count($failures),
            $waits === [] ? null : ['channel' => $waits[0]['channel'], 'label' => self::milliseconds($waits[0]['waitMicro'])],
            $failures[0] ?? null,
            $pendingChannels,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $services
     * @return list<array<string, mixed>>
     */
    private static function collapsible(array $rows, array $services): array
    {
        $runStart = null;
        foreach ($rows as $index => $row) {
            $rows[$index]['serviceIndex'] = array_search($row['service'], $services, true);
            $continues = $row['boilerplate'] && $runStart !== null && $rows[$runStart]['service'] === $row['service'];
            if ($row['boilerplate'] && ! $continues) {
                $runStart = $index;
                $rows[$index]['runSize'] = 0;
            }
            if (! $row['boilerplate']) {
                $runStart = null;
                continue;
            }
            $rows[$index]['run'] = $runStart;
            $rows[$runStart]['runSize']++;
        }
        foreach ($rows as $index => $row) {
            $rows[$index]['runSize'] ??= null;
            $rows[$index]['run'] ??= null;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $span
     * @return array<string, mixed>
     */
    private static function normalised(string $service, array $span): array
    {
        $started = self::parsedTime($span['startedAt'] ?? null);
        $ended = self::parsedTime($span['endedAt'] ?? null);
        $startMicro = $started === null ? null : self::microseconds($started);

        return [
            ...$span,
            'service' => $service,
            'spanId' => (string) $span['spanId'],
            'parentSpanId' => is_string($span['parentSpanId'] ?? null) ? $span['parentSpanId'] : null,
            'name' => (string) ($span['name'] ?? ''),
            'kind' => (string) ($span['kind'] ?? ''),
            'status' => (string) ($span['status'] ?? 'unset'),
            'attributes' => is_array($span['attributes'] ?? null) ? $span['attributes'] : [],
            'events' => is_array($span['events'] ?? null) ? $span['events'] : [],
            'startMicro' => $startMicro,
            'endMicro' => $startMicro === null ? null : max($startMicro, $ended === null ? $startMicro : self::microseconds($ended)),
            'timeInvalid' => $started === null || $ended === null,
        ];
    }

    private static function parsedTime(mixed $time): ?DateTimeImmutable
    {
        if (! is_string($time) || $time === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($time);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param list<array<string, mixed>> $spans
     * @return list<array<string, mixed>>
     */
    private static function withTimesOfInvalidSpansAtTheStart(array $spans): array
    {
        $starts = array_filter(array_column($spans, 'startMicro'), fn (?int $start): bool => $start !== null);
        $fallback = $starts === [] ? 0 : min($starts);
        foreach ($spans as $index => $span) {
            if ($span['startMicro'] === null) {
                $spans[$index]['startMicro'] = $fallback;
                $spans[$index]['endMicro'] = $fallback;
            }
        }

        return $spans;
    }

    /**
     * @param array<string, mixed> $span
     * @param list<array<string, mixed>> $spans
     * @param array<string, list<int>> $indexOf
     */
    private static function parentIndexOf(array $span, array $spans, array $indexOf): ?int
    {
        $candidates = $span['parentSpanId'] === null ? [] : ($indexOf[$span['parentSpanId']] ?? []);
        $sameService = array_values(array_filter($candidates, fn (int $index): bool => $spans[$index]['service'] === $span['service'] && $spans[$index]['spanId'] !== $span['spanId']));
        $others = array_values(array_filter($candidates, fn (int $index): bool => $spans[$index]['spanId'] !== $span['spanId']));

        return $sameService[0] ?? $others[0] ?? null;
    }

    /**
     * @param array<string, mixed> $span
     * @param array<string, mixed>|null $parent
     * @param list<array<string, mixed>> $children
     * @param array<string, array<string, array<string, mixed>>> $deadLettersByService
     * @return array<string, mixed>
     */
    private static function rowOf(array $span, int $depth, ?array $parent, array $children, int $flowStart, array $deadLettersByService): array
    {
        $attributes = $span['attributes'];
        $messageId = is_scalar($attributes[self::MESSAGE_ID] ?? null) ? (string) $attributes[self::MESSAGE_ID] : null;
        $isChannelSend = $span['kind'] === 'producer' && isset($attributes[self::CHANNEL]);
        $consumed = $isChannelSend && array_filter($children, fn (array $child): bool => $child['kind'] === 'consumer') !== [];
        $unprocessed = $isChannelSend && ! $consumed;
        $deadLetter = $messageId === null || ! ($unprocessed || $span['status'] === 'error') ? null : self::deadLetterOf($deadLettersByService, $span['service'], $messageId);
        $failed = ($unprocessed && $deadLetter !== null) || $span['status'] === 'error';
        $pending = $unprocessed && $deadLetter === null;
        $queueWait = $span['kind'] === 'consumer' && $parent !== null && $parent['kind'] === 'producer' ? $span['startMicro'] - $parent['endMicro'] : null;

        return [
            'service' => $span['service'],
            'spanId' => $span['spanId'],
            'name' => $span['name'],
            'endpoint' => is_scalar($attributes[self::ENDPOINT] ?? null) ? (string) $attributes[self::ENDPOINT] : '',
            'kind' => $span['kind'],
            'startedAt' => self::time($span['startMicro']),
            'offsetLabel' => '+' . self::milliseconds($span['startMicro'] - $flowStart),
            'durationLabel' => self::milliseconds($span['endMicro'] - $span['startMicro']),
            'durationMicro' => $span['endMicro'] - $span['startMicro'],
            'timeInvalid' => $span['timeInvalid'],
            'offsetMicro' => $span['startMicro'] - $flowStart,
            'waitMicro' => max(0, $queueWait ?? 0),
            'boilerplate' => in_array($span['kind'], ['producer', 'consumer'], true) && ! $failed && ! $pending && ($queueWait ?? 0) <= 0,
            'parentService' => $parent['service'] ?? null,
            'function' => is_scalar($attributes['code.function.name'] ?? null) ? (string) $attributes['code.function.name'] : null,
            'queueWaitLabel' => match (true) {
                $queueWait === null => '',
                $queueWait < 0 => 'clock skew between services (−' . self::milliseconds(-$queueWait) . ')',
                default => self::milliseconds($queueWait),
            },
            'clockSkew' => $queueWait !== null && $queueWait < 0,
            'status' => $pending ? 'pending' : ($failed ? 'failed' : $span['status']),
            'depth' => $depth,
            'hop' => $parent !== null && $parent['service'] !== $span['service'],
            'pending' => $pending,
            'failed' => $failed,
            'error' => $failed ? self::errorOf($span, $deadLetter) : '',
            'deadLetter' => $deadLetter,
            'channel' => is_scalar($attributes[self::CHANNEL] ?? null) ? (string) $attributes[self::CHANNEL] : null,
            'destination' => is_scalar($attributes['messaging.destination.name'] ?? null) ? (string) $attributes['messaging.destination.name'] : null,
            'messageId' => $messageId,
            'attributes' => $attributes,
            'events' => $span['events'],
        ];
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $deadLettersByService
     * @return array{service: string, messageId: string, exceptionClass: string, exceptionMessage: string}|null
     */
    private static function deadLetterOf(array $deadLettersByService, string $service, string $messageId): ?array
    {
        $owners = [$service, ...array_diff(array_keys($deadLettersByService), [$service])];
        foreach ($owners as $owner) {
            $entry = $deadLettersByService[$owner][$messageId] ?? null;
            if ($entry !== null) {
                return ['service' => $owner, 'messageId' => $messageId, 'exceptionClass' => (string) ($entry['exceptionClass'] ?? ''), 'exceptionMessage' => (string) ($entry['exceptionMessage'] ?? '')];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $span
     * @param array{exceptionClass: string, exceptionMessage: string}|null $deadLetter
     */
    private static function errorOf(array $span, ?array $deadLetter): string
    {
        if ($deadLetter !== null) {
            return $deadLetter['exceptionClass'] . ': ' . $deadLetter['exceptionMessage'];
        }
        foreach ($span['events'] as $event) {
            if (($event['name'] ?? null) === 'exception') {
                $type = $event['attributes']['exception.type'] ?? '';
                $message = $event['attributes']['exception.message'] ?? ($span['statusDescription'] ?? '');

                return trim((is_scalar($type) ? (string) $type : '') . ': ' . (is_scalar($message) ? (string) $message : ''), ': ');
            }
        }

        return is_string($span['statusDescription'] ?? null) ? $span['statusDescription'] : '';
    }

    private static function microseconds(DateTimeImmutable $time): int
    {
        return (int) $time->format('U') * 1_000_000 + (int) $time->format('u');
    }

    private static function time(int $microseconds): string
    {
        return gmdate('Y-m-d H:i:s', intdiv($microseconds, 1_000_000)) . '.' . sprintf('%03d', intdiv($microseconds % 1_000_000, 1000));
    }

    private static function milliseconds(int $microseconds): string
    {
        return rtrim(rtrim(number_format($microseconds / 1000, 1, '.', ''), '0'), '.') . ' ms';
    }
}
