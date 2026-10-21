<?php

declare(strict_types=1);

namespace App\Trace;

use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;

final readonly class TraceLoader
{
    public function __construct(private TraceQueries $queries)
    {
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     */
    public function load(string $traceId, array $snapshots): TraceView
    {
        $asked = [];
        foreach ($snapshots as $snapshot) {
            if ($snapshot->status === ServiceStatus::Connected && is_array($snapshot->snapshot['tracing'] ?? null)) {
                $asked[] = $snapshot->service;
            }
        }
        $answers = [];
        foreach ($this->queries->askAll($asked, TraceQuery::trace($traceId)) as $answer) {
            $answers[$answer->service] = $answer;
        }

        $states = [];
        $spans = [];
        $deadLetters = [];
        foreach ($snapshots as $snapshot) {
            $answer = $answers[$snapshot->service] ?? null;
            $states[] = [
                'service' => $snapshot->service,
                'state' => match (true) {
                    $answer !== null => $answer->state->value,
                    $snapshot->status !== ServiceStatus::Connected => 'not_connected',
                    default => 'tracing_disabled',
                },
                'status' => $snapshot->status->name,
                'failure' => $answer?->failure,
                'truncated' => (bool) ($answer?->result['truncated'] ?? false),
                'totalSpans' => (int) ($answer?->result['totalSpans'] ?? 0),
            ];
            $spans[$snapshot->service] = $answer?->result['spans'] ?? [];
            foreach ($snapshot->snapshot['deadLetters']['recent'] ?? [] as $entry) {
                if (is_string($entry['messageId'] ?? null)) {
                    $deadLetters[$snapshot->service][$entry['messageId']] = $entry;
                }
            }
        }

        return new TraceView($traceId, CrossServiceTrace::merge($spans, $deadLetters), $states, TraceQueries::anyWaiting(array_values($answers)));
    }
}
