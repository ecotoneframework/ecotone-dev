<?php

declare(strict_types=1);

namespace App\Ui;

use App\Graph\Diagram;
use App\Graph\DiagramNode;
use App\Graph\TraceHighlight;
use App\Trace\CrossServiceTrace;

final readonly class TraceSteps
{
    /**
     * @param list<array{step: ?int, service: string, hopFrom: ?string, name: string, depth: int, status: string, failed: bool, pending: bool, durationLabel: string, offsetMicro: int, durationMicro: int, waitMicro: int, waitLabel: string, error: string}> $steps
     */
    private function __construct(
        public array $steps,
        public int $totalMicro,
        public string $duration,
        public ?string $longestWait,
    ) {
    }

    public static function of(CrossServiceTrace $trace, TraceHighlight $highlight, Diagram $diagram): self
    {
        $drawn = array_filter(array_map(static fn (DiagramNode $node): ?int => $node->step, $diagram->nodes));
        $steps = [];
        foreach ($trace->rows as $index => $row) {
            if ($row['boilerplate']) {
                continue;
            }
            $step = $highlight->stepOfRow($index);
            $steps[] = [
                'step' => in_array($step, $drawn, true) ? $step : null,
                'service' => $row['service'],
                'hopFrom' => $row['hop'] ? $row['parentService'] : null,
                'name' => $row['name'],
                'depth' => $row['depth'],
                'status' => $row['status'],
                'failed' => $row['failed'],
                'pending' => $row['pending'],
                'durationLabel' => $row['durationLabel'],
                'offsetMicro' => $row['offsetMicro'],
                'durationMicro' => $row['durationMicro'],
                'waitMicro' => $row['waitMicro'],
                'waitLabel' => $row['waitMicro'] > 0 ? $row['queueWaitLabel'] : '',
                'error' => $row['error'],
            ];
        }
        $longestWait = $trace->rows === [] ? 0 : max(array_column($trace->rows, 'waitMicro'));

        return new self($steps, $trace->totalMicro, $trace->durationLabel, $longestWait > 0 ? TraceHighlight::milliseconds($longestWait) : null);
    }
}
