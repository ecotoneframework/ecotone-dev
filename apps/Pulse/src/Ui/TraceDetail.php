<?php

declare(strict_types=1);

namespace App\Ui;

use App\Snapshot\ServiceScope;
use App\Snapshot\ServiceSnapshot;
use App\Trace\CrossServiceTrace;
use App\Trace\TraceView;
use Ecotone\Api\Tracing\SpanAttribute;

final readonly class TraceDetail
{
    private const ASKED = ['answered', 'waiting', 'refused', 'not_answering'];

    /**
     * @param array{row: array<string, mixed>, index: ?int, exception: string, explanation: string, where: list<array{label: string, title?: string}>, handler: ?string}|null $failure
     * @param array{label: string, channel: ?string, index: int}|null $longestWait
     * @param array{label: string, name: string, service: string, index: int, failed: bool}|null $ownTime
     * @param list<string> $waitingFor
     */
    private function __construct(
        public Status $status,
        public ?string $reason,
        public string $sentence,
        public ?ServiceScope $root,
        public ?string $flowType,
        public ?array $failure,
        public ?array $longestWait,
        public ?array $ownTime,
        public ?int $firstHopIndex,
        public int $asked,
        public string $answersSummary,
        public Status $answersStatus,
        public bool $answersOpen,
        public array $waitingFor,
    ) {
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     */
    public static function of(TraceView $view, array $snapshots): self
    {
        $trace = $view->trace;
        $rows = $trace->rows;
        $asked = array_values(array_filter($view->states, static fn (array $state): bool => in_array($state['state'], self::ASKED, true)));
        $answered = count(array_filter($asked, static fn (array $state): bool => $state['state'] === 'answered'));
        $waitingFor = array_values(array_map(static fn (array $state): string => self::scopeLabel($state), array_filter($asked, static fn (array $state): bool => $state['state'] === 'waiting')));
        $troubled = array_filter($asked, static fn (array $state): bool => in_array($state['state'], ['refused', 'not_answering'], true));
        $failure = self::failureOf($rows, $trace);
        [$status, $reason, $sentence] = self::verdictOf($trace, $failure, count($asked), $answered, $view->waiting);
        $root = self::rootOf($rows, $view->states);
        $flowType = $root === null ? null : self::flowTypeOf($rows, $root, $snapshots);

        return new self(
            $status,
            $reason,
            $sentence,
            $flowType === null ? $root : $flowType[1],
            $flowType[0] ?? null,
            $failure,
            self::longestWaitOf($rows),
            $failure !== null && $failure['index'] !== null
                ? ['label' => (string) $failure['row']['durationLabel'], 'name' => $failure['handler'] ?? (string) $failure['row']['name'], 'service' => (string) $failure['row']['service'], 'index' => $failure['index'], 'failed' => true]
                : self::ownTimeOf($rows),
            self::firstIndexOf($rows, static fn (array $row): bool => (bool) $row['hop']),
            count($asked),
            self::answersSummaryOf($view->states, count($asked), $answered),
            match (true) {
                $troubled !== [] => Status::Warning,
                $waitingFor !== [] => Status::Info,
                default => Status::Ok,
            },
            $answered < count($asked) || $asked === [],
            $waitingFor,
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function scopeLabel(array $state): string
    {
        return $state['service'] . ($state['tenant'] ? ' (tenant ' . $state['tenant'] . ')' : '');
    }

    /**
     * @param array{row: array<string, mixed>, exception: string, handler: ?string}|null $failure
     * @return array{0: Status, 1: ?string, 2: string}
     */
    private static function verdictOf(CrossServiceTrace $trace, ?array $failure, int $asked, int $answered, bool $waiting): array
    {
        if ($trace->isEmpty()) {
            return $waiting
                ? [Status::Info, null, sprintf('Asking %d %s for the steps of this trace; %d answered so far.', $asked, $asked === 1 ? 'service' : 'services', $answered)]
                : [Status::Unknown, $asked === 0 ? 'no connected service tracks flows, so Pulse could not ask any' : 'no service that answered stored a step of this trace id', 'Pulse found no step of this trace.'];
        }
        if ($failure !== null) {
            $row = $failure['row'];

            return [Status::Warning, null, sprintf(
                'Failed in %s at %s%s, %s into the flow%s',
                $row['service'],
                $failure['handler'] === null ? 'step ' . $row['name'] : 'handler ' . $failure['handler'],
                $row['takenFrom'] === null ? '' : ' after it was taken from ' . $row['takenFrom'],
                ltrim((string) $row['offsetLabel'], '+'),
                $failure['exception'] === '' ? '.' : ': ' . $failure['exception'] . '.',
            )];
        }
        if ($trace->status === 'pending') {
            return [Status::Info, null, sprintf(
                'Still running: a message waits on %s and no consumer has handled it yet.',
                implode(', ', $trace->pendingChannels) ?: 'a channel',
            )];
        }
        $services = count($trace->services);

        return [Status::Ok, null, sprintf('Completed in %s across %d %s; no step failed.', $trace->durationLabel, $services, $services === 1 ? 'service' : 'services')];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{row: array<string, mixed>, index: ?int, exception: string, explanation: string, where: list<array{label: string, title?: string}>, handler: ?string}|null
     */
    private static function failureOf(array $rows, CrossServiceTrace $trace): ?array
    {
        $row = $trace->firstFailure;
        if ($row === null) {
            return null;
        }
        $index = self::firstIndexOf($rows, static fn (array $candidate): bool => $candidate['spanId'] === $row['spanId'] && $candidate['service'] === $row['service']);
        $deadLetter = $row['deadLetter'];
        $exception = match (true) {
            $deadLetter !== null => self::shortName($deadLetter['exceptionClass']),
            str_contains((string) $row['error'], ': ') => self::shortName(explode(': ', (string) $row['error'], 2)[0]),
            default => '',
        };
        $unprocessedSend = $row['kind'] === 'producer' && $row['channel'] !== null;
        $handler = self::handlerOf($row);
        $explanation = match (true) {
            $deadLetter !== null && $unprocessedSend => sprintf('The message sent to %s failed in its handler and is kept as a failed message in %s: its work has not happened until it is replayed. %s stored no step of the failed attempt, so Pulse marks the send instead.', $row['channel'], $deadLetter['service'], $row['service']),
            $deadLetter !== null => sprintf('The handler threw and its message is kept as a failed message in %s: its work has not happened until it is replayed.', $deadLetter['service']),
            default => sprintf('This step ended with an exception. Pulse found no failed message of it in the latest snapshot of %s.', $row['service']),
        };
        $where = [['label' => (string) $row['service']]];
        if ($row['takenFrom'] !== null) {
            $where[] = ['label' => (string) $row['takenFrom'], 'title' => 'The channel the message was taken from'];
        }
        $where[] = $handler === null ? ['label' => (string) $row['name']] : ['label' => $handler, 'title' => (string) $row['name']];
        if ($row['endpoint'] !== '') {
            $where[] = ['label' => (string) $row['endpoint'], 'title' => 'Endpoint id of the handler'];
        }

        return ['row' => $row, 'index' => $index, 'exception' => $exception, 'explanation' => $explanation, 'where' => $where, 'handler' => $handler];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{label: string, channel: ?string, index: int}|null
     */
    private static function longestWaitOf(array $rows): ?array
    {
        $longest = null;
        foreach ($rows as $index => $row) {
            if ($row['waitMicro'] > 0 && ($longest === null || $row['waitMicro'] > $rows[$longest]['waitMicro'])) {
                $longest = $index;
            }
        }
        if ($longest === null) {
            return null;
        }
        $parentChannel = null;
        for ($at = $longest - 1; $at >= 0; $at--) {
            if ($rows[$at]['depth'] < $rows[$longest]['depth']) {
                $parentChannel = $rows[$at]['channel'];
                break;
            }
        }

        return ['label' => self::milliseconds($rows[$longest]['waitMicro']), 'channel' => $rows[$longest]['channel'] ?? $parentChannel, 'index' => $longest];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{label: string, name: string, service: string, index: int, failed: bool}|null
     */
    private static function ownTimeOf(array $rows): ?array
    {
        $busiest = null;
        $busiestOwn = -1;
        foreach ($rows as $index => $row) {
            if ($row['timeInvalid']) {
                continue;
            }
            $start = $row['offsetMicro'];
            $end = $start + $row['durationMicro'];
            $covered = [];
            for ($child = $index + 1; $child < count($rows) && $rows[$child]['depth'] > $row['depth']; $child++) {
                if ($rows[$child]['depth'] === $row['depth'] + 1 && ! $rows[$child]['timeInvalid']) {
                    $from = max($start, $rows[$child]['offsetMicro']);
                    $to = min($end, $rows[$child]['offsetMicro'] + $rows[$child]['durationMicro']);
                    if ($to > $from) {
                        $covered[] = [$from, $to];
                    }
                }
            }
            $own = $row['durationMicro'] - self::unionLength($covered);
            if ($own > $busiestOwn) {
                $busiest = $index;
                $busiestOwn = $own;
            }
        }

        return $busiest === null ? null : ['label' => self::milliseconds($busiestOwn), 'name' => (string) $rows[$busiest]['name'], 'service' => (string) $rows[$busiest]['service'], 'index' => $busiest, 'failed' => false];
    }

    /**
     * @param list<array{0: int, 1: int}> $intervals
     */
    private static function unionLength(array $intervals): int
    {
        sort($intervals);
        $length = 0;
        $reach = null;
        foreach ($intervals as [$from, $to]) {
            if ($reach === null || $from > $reach) {
                $length += $to - $from;
                $reach = $to;
            } elseif ($to > $reach) {
                $length += $to - $reach;
                $reach = $to;
            }
        }

        return $length;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array{service: string, tenant: ?string, state: string, totalSpans: int}|array<string, mixed>> $states
     */
    private static function rootOf(array $rows, array $states): ?ServiceScope
    {
        if ($rows === []) {
            return null;
        }
        $service = (string) $rows[0]['service'];
        $tenants = array_values(array_filter($states, static fn (array $state): bool => $state['service'] === $service && $state['tenant'] !== null && $state['totalSpans'] > 0));

        return count($tenants) === 1 ? ServiceScope::ofTenant($service, (string) $tenants[0]['tenant']) : ServiceScope::ofService($service);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<ServiceSnapshot> $snapshots
     * @return array{0: string, 1: ServiceScope}|null
     */
    private static function flowTypeOf(array $rows, ServiceScope $root, array $snapshots): ?array
    {
        $entry = $rows[0];
        $destination = self::destinationOf($entry);
        if ($destination === null || $entry['kind'] === 'consumer') {
            $sends = [];
            for ($child = 1; $child < count($rows) && $rows[$child]['depth'] > 0; $child++) {
                if ($rows[$child]['depth'] === 1 && $rows[$child]['service'] === $entry['service'] && ($rows[$child]['attributes'][SpanAttribute::MESSAGING_OPERATION_TYPE] ?? null) === SpanAttribute::OPERATION_SEND && self::destinationOf($rows[$child]) !== null) {
                    $sends[] = [$rows[$child]['kind'] === 'producer' ? 1 : 0, $rows[$child]['offsetMicro'], (string) self::destinationOf($rows[$child])];
                }
            }
            sort($sends);
            $destination = $sends === [] ? $destination : $sends[0][2];
        }
        $name = (string) $entry['name'];
        $flowType = $destination ?? (explode(' ', $name, 2)[1] ?? $name);
        foreach ($snapshots as $snapshot) {
            if ($snapshot->service !== $root->service || ($root->tenant !== null && $snapshot->tenant !== $root->tenant)) {
                continue;
            }
            foreach ($snapshot->snapshot['tracing']['flowTypes'] ?? [] as $statistics) {
                if (is_array($statistics) && ($statistics['flowType'] ?? null) === $flowType) {
                    return [$flowType, ServiceScope::fromKey($snapshot->scope())];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function destinationOf(array $row): ?string
    {
        $destination = $row['attributes'][SpanAttribute::MESSAGING_DESTINATION_NAME] ?? null;

        return is_scalar($destination) && (string) $destination !== '' ? (string) $destination : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>): bool $matches
     */
    private static function firstIndexOf(array $rows, callable $matches): ?int
    {
        foreach ($rows as $index => $row) {
            if ($matches($row)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $states
     */
    private static function answersSummaryOf(array $states, int $asked, int $answered): string
    {
        $count = static fn (string $state): int => count(array_filter($states, static fn (array $candidate): bool => $candidate['state'] === $state));
        $notAsked = count($states) - $asked;
        $parts = [match (true) {
            $asked === 0 => 'No service was asked',
            $answered === $asked && $asked === 1 => 'Pulse asked 1 service and it answered',
            $answered === $asked => sprintf('Pulse asked %d services and all answered', $asked),
            default => sprintf('Pulse asked %d services and %d answered', $asked, $answered),
        }];
        foreach (['waiting' => 'waiting', 'refused' => 'refused', 'not_answering' => 'not answering'] as $state => $label) {
            if ($count($state) > 0) {
                $parts[] = $count($state) . ' ' . $label;
            }
        }
        if ($notAsked > 0) {
            $parts[] = sprintf('%d not asked: not connected or not tracking flows', $notAsked);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function handlerOf(array $row): ?string
    {
        if ($row['kind'] === 'producer' || $row['kind'] === 'consumer') {
            return null;
        }
        $name = (string) $row['name'];

        return str_starts_with($name, 'process ') ? substr($name, strlen('process ')) : null;
    }

    private static function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    private static function milliseconds(int $microseconds): string
    {
        return rtrim(rtrim(number_format($microseconds / 1000, 1, '.', ''), '0'), '.') . ' ms';
    }
}
