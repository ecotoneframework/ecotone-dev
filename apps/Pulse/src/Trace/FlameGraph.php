<?php

declare(strict_types=1);

namespace App\Trace;

final readonly class FlameGraph
{
    public const MINIMUM_WIDTH_PERCENT = 0.5;
    private const TARGET_TICKS = 5;

    /**
     * @param list<array<string, mixed>> $bars
     * @param list<array{label: string, left: float}> $ticks
     * @param list<array{service: string, serviceIndex: int}> $legend
     */
    private function __construct(
        public array $bars,
        public int $laneCount,
        public int $skipped,
        public array $ticks,
        public array $legend,
    ) {
    }

    public static function of(CrossServiceTrace $trace): self
    {
        $total = max(1, $trace->totalMicro);
        $bars = [];
        $laneOfDepth = [];
        $occupied = [];
        $skipped = 0;
        foreach ($trace->rows as $index => $row) {
            if ($row['timeInvalid']) {
                $skipped++;
                continue;
            }
            $lane = $row['depth'] === 0 ? 0 : ($laneOfDepth[$row['depth'] - 1] ?? -1) + 1;
            $from = $row['offsetMicro'] - $row['waitMicro'];
            $to = $row['offsetMicro'] + max(1, $row['durationMicro']);
            while (self::overlaps($occupied[$lane] ?? [], $from, $to)) {
                $lane++;
            }
            $occupied[$lane][] = [$from, $to];
            $laneOfDepth[$row['depth']] = $lane;

            $bars[] = [
                'index' => $index,
                'lane' => $lane,
                'left' => round($row['offsetMicro'] / $total * 100, 3),
                'width' => max(self::MINIMUM_WIDTH_PERCENT, round($row['durationMicro'] / $total * 100, 3)),
                'waitLeft' => round(($row['offsetMicro'] - $row['waitMicro']) / $total * 100, 3),
                'waitWidth' => round($row['waitMicro'] / $total * 100, 3),
                'row' => $row,
            ];
        }

        return new self(
            $bars,
            $occupied === [] ? 0 : max(array_keys($occupied)) + 1,
            $skipped,
            self::ticks($total),
            array_map(fn (string $service, int $position): array => ['service' => $service, 'serviceIndex' => $position % 8], $trace->services, array_keys($trace->services)),
        );
    }

    /**
     * @param list<array{0: int, 1: int}> $intervals
     */
    private static function overlaps(array $intervals, int $from, int $to): bool
    {
        foreach ($intervals as [$otherFrom, $otherTo]) {
            if ($from < $otherTo && $otherFrom < $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{label: string, left: float}>
     */
    private static function ticks(int $total): array
    {
        $rough = $total / self::TARGET_TICKS;
        $magnitude = 10 ** floor(log10($rough));
        $step = $magnitude;
        foreach ([1, 2, 5, 10] as $multiple) {
            $step = $magnitude * $multiple;
            if ($step >= $rough) {
                break;
            }
        }
        $ticks = [];
        $step = max(1, (int) round($step));
        for ($at = 0; $at <= $total; $at += $step) {
            $ticks[] = ['label' => self::label($at, $total), 'left' => round($at / $total * 100, 3)];
        }

        return $ticks;
    }

    private static function label(int $microseconds, int $total): string
    {
        if ($total >= 2_000_000) {
            return rtrim(rtrim(number_format($microseconds / 1_000_000, 2, '.', ''), '0'), '.') . ' s';
        }

        return rtrim(rtrim(number_format($microseconds / 1000, 2, '.', ''), '0'), '.') . ' ms';
    }
}
