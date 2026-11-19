<?php

declare(strict_types=1);

namespace Test\Ecotone\BuildFlow;

use Closure;

/**
 * licence Apache-2.0
 */
final class FlowExecutionChange
{
    public const UNDER = 'under';
    public const OUT_OF = 'out of';
    private const SIMILAR_ENOUGH = 0.5;

    /**
     * @param list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}> $beforeSteps
     * @param list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}> $afterSteps
     * @return list<array{change: string, step: string, steps?: int, times?: int, movement?: string, parent?: string, changedInside?: bool, hints?: list<array{key: string, value: string, shownInLines: bool}>, hintChanges?: list<array{key: string, before: string, now: string}>}>
     */
    public static function between(array $beforeSteps, array $afterSteps): array
    {
        $before = array_map(fn (array $step): string => str_repeat('  ', $step['depth']) . $step['label'], $beforeSteps);
        $after = array_map(fn (array $step): string => str_repeat('  ', $step['depth']) . $step['label'], $afterSteps);
        $hints = ['before' => array_column($beforeSteps, 'hints'), 'after' => array_column($afterSteps, 'hints')];
        $beforeTree = self::treeOf($before);
        $afterTree = self::treeOf($after);
        $plain = self::aligned(beforeCount: count($before), afterCount: count($after), same: fn (int $beforeLine, int $afterLine): bool => $before[$beforeLine] === $after[$afterLine]);
        $candidates = self::moveCandidates(beforeTree: $beforeTree, afterTree: $afterTree, plain: $plain);
        $rejected = [];
        do {
            $moves = self::chosenMoves($candidates, $rejected);
            $aligned = self::alignedOutside(moves: $moves, before: $before, after: $after, beforeTree: $beforeTree, afterTree: $afterTree);
            $failed = self::invalidMoves(moves: $moves, aligned: $aligned, beforeTree: $beforeTree, afterTree: $afterTree);
            $rejected += $failed;
        } while ($failed !== []);

        return self::mergedRepeats(self::entriesOf(aligned: $aligned, moves: $moves, before: $before, after: $after, afterTree: $afterTree, hints: $hints));
    }

    /**
     * @param list<string> $lines
     * @return array{depth: list<int>, text: list<string>, parent: list<int|null>, end: list<int>}
     */
    private static function treeOf(array $lines): array
    {
        $tree = ['depth' => [], 'text' => [], 'parent' => [], 'end' => []];
        $open = [];
        foreach ($lines as $line => $step) {
            $depth = intdiv(strspn($step, ' '), 2);
            while ($open !== [] && $tree['depth'][end($open)] >= $depth) {
                $tree['end'][array_pop($open)] = $line;
            }
            $tree['depth'][$line] = $depth;
            $tree['text'][$line] = ltrim($step, ' ');
            $tree['parent'][$line] = $open === [] ? null : end($open);
            $open[] = $line;
        }
        foreach ($open as $line) {
            $tree['end'][$line] = count($lines);
        }
        ksort($tree['end']);

        return $tree;
    }

    /**
     * @param array{depth: list<int>, text: list<string>, parent: list<int|null>, end: list<int>} $beforeTree
     * @param array{depth: list<int>, text: list<string>, parent: list<int|null>, end: list<int>} $afterTree
     * @param list<array{0: int|null, 1: int|null}> $plain
     * @return list<array{before: int, after: int, inside: list<array{0: int|null, 1: int|null}>, mates: int, parent: string}>
     */
    private static function moveCandidates(array $beforeTree, array $afterTree, array $plain): array
    {
        $removed = $added = [];
        foreach ($plain as [$beforeLine, $afterLine]) {
            if ($afterLine === null) {
                $removed[$beforeLine] = true;
            } elseif ($beforeLine === null) {
                $added[$afterLine] = true;
            }
        }
        $candidates = [];
        foreach ($beforeTree['text'] as $beforeLine => $text) {
            if (! isset($removed[$beforeLine])) {
                continue;
            }
            $beforeSize = $beforeTree['end'][$beforeLine] - $beforeLine;
            foreach ($afterTree['text'] as $afterLine => $afterText) {
                $afterSize = $afterTree['end'][$afterLine] - $afterLine;
                if ($afterText !== $text || $afterTree['depth'][$afterLine] === $beforeTree['depth'][$beforeLine] || $afterSize > 2 * $beforeSize || $beforeSize > 2 * $afterSize || ! isset($added[$afterLine])) {
                    continue;
                }
                $inside = self::alignedInside(beforeTree: $beforeTree, beforeRoot: $beforeLine, afterTree: $afterTree, afterRoot: $afterLine);
                $mates = count(array_filter($inside, fn (array $lines): bool => $lines[0] !== null && $lines[1] !== null));
                if ($inside[0] === [$beforeLine, $afterLine] && 2 * $mates >= self::SIMILAR_ENOUGH * ($beforeSize + $afterSize)) {
                    $candidates[] = ['before' => $beforeLine, 'after' => $afterLine, 'inside' => $inside, 'mates' => $mates, 'parent' => $afterTree['depth'][$afterLine] > $beforeTree['depth'][$beforeLine]
                        ? $afterTree['text'][(int) $afterTree['parent'][$afterLine]]
                        : $beforeTree['text'][(int) $beforeTree['parent'][$beforeLine]]];
                }
            }
        }
        usort($candidates, fn (array $one, array $other): int => [$other['mates'], $one['before'], $one['after']] <=> [$one['mates'], $other['before'], $other['after']]);

        return $candidates;
    }

    /**
     * @param array{depth: list<int>, text: list<string>, parent: list<int|null>, end: list<int>} $beforeTree
     * @param array{depth: list<int>, text: list<string>, parent: list<int|null>, end: list<int>} $afterTree
     * @return list<array{0: int|null, 1: int|null}>
     */
    private static function alignedInside(array $beforeTree, int $beforeRoot, array $afterTree, int $afterRoot): array
    {
        $relative = fn (array $tree, int $root): array => array_map(
            fn (int $line): string => ($tree['depth'][$line] - $tree['depth'][$root]) . ' ' . $tree['text'][$line],
            range($root, $tree['end'][$root] - 1),
        );
        $beforeSteps = $relative($beforeTree, $beforeRoot);
        $afterSteps = $relative($afterTree, $afterRoot);

        return array_map(
            fn (array $lines): array => [$lines[0] === null ? null : $beforeRoot + $lines[0], $lines[1] === null ? null : $afterRoot + $lines[1]],
            self::aligned(beforeCount: count($beforeSteps), afterCount: count($afterSteps), same: fn (int $beforeLine, int $afterLine): bool => $beforeSteps[$beforeLine] === $afterSteps[$afterLine]),
        );
    }

    /**
     * @param list<array{before: int, after: int, inside: list<array{0: int|null, 1: int|null}>, mates: int, parent: string}> $candidates
     * @param array<string, true> $rejected
     * @return list<array{before: int, after: int, inside: list<array{0: int|null, 1: int|null}>, mates: int, parent: string}>
     */
    private static function chosenMoves(array $candidates, array $rejected): array
    {
        $chosen = [];
        $usedBefore = $usedAfter = [];
        foreach ($candidates as $candidate) {
            $beforeLines = array_filter(array_column($candidate['inside'], 0), is_int(...));
            $afterLines = array_filter(array_column($candidate['inside'], 1), is_int(...));
            if (isset($rejected[$candidate['before'] . ':' . $candidate['after']]) || array_intersect_key($usedBefore, array_flip($beforeLines)) !== [] || array_intersect_key($usedAfter, array_flip($afterLines)) !== []) {
                continue;
            }
            $usedBefore += array_fill_keys($beforeLines, true);
            $usedAfter += array_fill_keys($afterLines, true);
            $chosen[] = $candidate;
        }

        return $chosen;
    }

    /**
     * @param list<array{before: int, after: int, inside: list<array{0: int|null, 1: int|null}>, mates: int, parent: string}> $moves
     * @param list<string> $before
     * @param list<string> $after
     * @param array{depth: list<int>, text: list<string>, parent: list<int|null>, end: list<int>} $beforeTree
     * @param array{depth: list<int>, text: list<string>, parent: list<int|null>, end: list<int>} $afterTree
     * @return list<array{0: int|null, 1: int|null}>
     */
    private static function alignedOutside(array $moves, array $before, array $after, array $beforeTree, array $afterTree): array
    {
        $movedBefore = $movedAfter = [];
        foreach ($moves as $move) {
            $movedBefore += array_fill_keys(range($move['before'], $beforeTree['end'][$move['before']] - 1), true);
            $movedAfter += array_fill_keys(range($move['after'], $afterTree['end'][$move['after']] - 1), true);
        }
        $keptBefore = array_values(array_diff_key(array_keys($before), $movedBefore));
        $keptAfter = array_values(array_diff_key(array_keys($after), $movedAfter));

        return array_map(
            fn (array $lines): array => [$lines[0] === null ? null : $keptBefore[$lines[0]], $lines[1] === null ? null : $keptAfter[$lines[1]]],
            self::aligned(beforeCount: count($keptBefore), afterCount: count($keptAfter), same: fn (int $beforeLine, int $afterLine): bool => $before[$keptBefore[$beforeLine]] === $after[$keptAfter[$afterLine]]),
        );
    }

    /**
     * @param list<array{before: int, after: int, inside: list<array{0: int|null, 1: int|null}>, mates: int, parent: string}> $moves
     * @param list<array{0: int|null, 1: int|null}> $aligned
     * @param array{depth: list<int>, text: list<string>, parent: list<int|null>, end: list<int>} $beforeTree
     * @param array{depth: list<int>, text: list<string>, parent: list<int|null>, end: list<int>} $afterTree
     * @return array<string, true>
     */
    private static function invalidMoves(array $moves, array $aligned, array $beforeTree, array $afterTree): array
    {
        $partnerOfBefore = $partnerOfAfter = [];
        foreach ($aligned as [$beforeLine, $afterLine]) {
            if ($beforeLine !== null && $afterLine !== null) {
                $partnerOfBefore[$beforeLine] = $afterLine;
                $partnerOfAfter[$afterLine] = $beforeLine;
            }
        }
        $invalid = [];
        foreach ($moves as $move) {
            $wrapsOrUnwraps = $afterTree['depth'][$move['after']] > $beforeTree['depth'][$move['before']]
                ? self::movedWithin(shallowLine: $move['before'], deepLine: $move['after'], shallowTree: $beforeTree, deepTree: $afterTree, partnerInDeep: $partnerOfBefore, partnerInShallow: $partnerOfAfter)
                : self::movedWithin(shallowLine: $move['after'], deepLine: $move['before'], shallowTree: $afterTree, deepTree: $beforeTree, partnerInDeep: $partnerOfAfter, partnerInShallow: $partnerOfBefore);
            if (! $wrapsOrUnwraps) {
                $invalid[$move['before'] . ':' . $move['after']] = true;
            }
        }

        return $invalid;
    }

    /**
     * @param array{depth: list<int>, text: list<string>, parent: list<int|null>, end: list<int>} $shallowTree
     * @param array{depth: list<int>, text: list<string>, parent: list<int|null>, end: list<int>} $deepTree
     * @param array<int, int> $partnerInDeep
     * @param array<int, int> $partnerInShallow
     */
    private static function movedWithin(int $shallowLine, int $deepLine, array $shallowTree, array $deepTree, array $partnerInDeep, array $partnerInShallow): bool
    {
        $shallowParent = $shallowTree['parent'][$shallowLine];
        $staysInItsParent = $shallowParent === null
            || (isset($partnerInDeep[$shallowParent]) && $partnerInDeep[$shallowParent] < $deepLine && $deepLine < $deepTree['end'][$partnerInDeep[$shallowParent]]);
        $deepParent = $deepTree['parent'][$deepLine];
        if (! $staysInItsParent || $deepParent === null) {
            return false;
        }
        if (! isset($partnerInShallow[$deepParent])) {
            return true;
        }
        $precedingSibling = null;
        for ($line = $shallowLine - 1; $line >= 0 && $precedingSibling === null; $line--) {
            if ($shallowTree['parent'][$line] === $shallowParent) {
                $precedingSibling = $line;
            }
        }

        return $precedingSibling !== null && $partnerInShallow[$deepParent] >= $precedingSibling && $partnerInShallow[$deepParent] < $shallowLine;
    }

    /**
     * @param list<array{0: int|null, 1: int|null}> $aligned
     * @param list<array{before: int, after: int, inside: list<array{0: int|null, 1: int|null}>, mates: int, parent: string}> $moves
     * @param list<string> $before
     * @param list<string> $after
     * @param array{depth: list<int>, text: list<string>, parent: list<int|null>, end: list<int>} $afterTree
     * @return list<array{change: string, step: string, steps?: int, times?: int, movement?: string, parent?: string, changedInside?: bool, signature?: string}>
     */
    private static function entriesOf(array $aligned, array $moves, array $before, array $after, array $afterTree, array $hints): array
    {
        usort($moves, fn (array $one, array $other): int => $one['after'] <=> $other['after']);
        $entries = [];
        foreach ([...$aligned, [null, PHP_INT_MAX]] as [$beforeLine, $afterLine]) {
            while ($moves !== [] && $afterLine !== null && $moves[0]['after'] < $afterLine) {
                array_push($entries, ...self::movedEntries(move: array_shift($moves), before: $before, after: $after, afterTree: $afterTree, hints: $hints));
            }
            $entries[] = $afterLine === PHP_INT_MAX ? null : self::entryOf(beforeLine: $beforeLine, afterLine: $afterLine, beforeStep: $beforeLine === null ? null : $before[$beforeLine], afterStep: $afterLine === null ? null : $after[$afterLine], hints: $hints);
        }

        return array_values(array_filter($entries));
    }

    /**
     * @param array{before: int, after: int, inside: list<array{0: int|null, 1: int|null}>, mates: int, parent: string} $move
     * @param list<string> $before
     * @param list<string> $after
     * @param array{depth: list<int>, text: list<string>, parent: list<int|null>, end: list<int>} $afterTree
     * @return list<array{change: string, step: string, steps?: int, times?: int, movement?: string, parent?: string, changedInside?: bool, signature?: string}>
     */
    private static function movedEntries(array $move, array $before, array $after, array $afterTree, array $hints): array
    {
        $deeper = intdiv(strspn($after[$move['after']], ' ') - strspn($before[$move['before']], ' '), 2);
        $changes = [];
        foreach (array_slice($move['inside'], 1) as [$beforeLine, $afterLine]) {
            $changes[] = self::entryOf(beforeLine: $beforeLine, afterLine: $afterLine, beforeStep: $beforeLine === null ? null : ($deeper > 0 ? str_repeat('  ', $deeper) . $before[$beforeLine] : substr($before[$beforeLine], -2 * $deeper)), afterStep: $afterLine === null ? null : $after[$afterLine], hints: $hints);
        }
        $rootHintChanges = self::hintChangesBetween($hints['before'][$move['before']], $hints['after'][$move['after']]);
        $changedInside = $rootHintChanges !== [] || array_filter($changes, fn (array $change): bool => $change['change'] !== FlowScenarioChange::UNCHANGED) !== [];
        $moved = [
            'change' => FlowScenarioChange::MOVED,
            'step' => $after[$move['after']],
            'steps' => $afterTree['end'][$move['after']] - $move['after'],
            'times' => 1,
            'movement' => $deeper > 0 ? self::UNDER : self::OUT_OF,
            'parent' => $move['parent'],
            'changedInside' => $changedInside,
            'signature' => implode("\n", array_slice($after, $move['after'], $afterTree['end'][$move['after']] - $move['after'])) . json_encode(array_slice($hints['after'], $move['after'], $afterTree['end'][$move['after']] - $move['after']), JSON_THROW_ON_ERROR),
            ...($rootHintChanges === [] ? [] : ['hintChanges' => $rootHintChanges]),
        ];

        return $changedInside ? [$moved, ...$changes] : [$moved];
    }

    /**
     * @param array{before: list<list<array{key: string, value: string, shownInLines: bool}>>, after: list<list<array{key: string, value: string, shownInLines: bool}>>} $hints
     * @return array<string, mixed>
     */
    private static function entryOf(?int $beforeLine, ?int $afterLine, ?string $beforeStep, ?string $afterStep, array $hints): array
    {
        $shownInLines = fn (array $stepHints): array => array_values(array_filter($stepHints, fn (array $hint): bool => $hint['shownInLines']));
        if ($beforeLine === null) {
            return ['change' => FlowScenarioChange::ADDED, 'step' => (string) $afterStep, 'hints' => $shownInLines($hints['after'][$afterLine])];
        }
        if ($afterLine === null) {
            return ['change' => FlowScenarioChange::REMOVED, 'step' => (string) $beforeStep, 'hints' => $shownInLines($hints['before'][$beforeLine])];
        }
        $changes = self::hintChangesBetween($hints['before'][$beforeLine], $hints['after'][$afterLine]);

        return $changes === []
            ? ['change' => FlowScenarioChange::UNCHANGED, 'step' => (string) $afterStep, 'hints' => $shownInLines($hints['after'][$afterLine])]
            : ['change' => FlowScenarioChange::CHANGED, 'step' => (string) $afterStep, 'hints' => array_values(array_filter($shownInLines($hints['after'][$afterLine]), fn (array $hint): bool => ! in_array($hint['key'], array_column($changes, 'key'), true))), 'hintChanges' => $changes];
    }

    /**
     * @param list<array{key: string, value: string, shownInLines: bool}> $before
     * @param list<array{key: string, value: string, shownInLines: bool}> $after
     * @return list<array{key: string, before: string, now: string, shownInLines: bool}>
     */
    private static function hintChangesBetween(array $before, array $after): array
    {
        $shownInLines = [];
        $valuesOf = function (array $hints) use (&$shownInLines): array {
            $values = [];
            foreach ($hints as $hint) {
                $values[$hint['key']][] = $hint['value'];
                $shownInLines[$hint['key']] = ($shownInLines[$hint['key']] ?? false) || $hint['shownInLines'];
            }

            return array_map(fn (array $list): string => implode('; ', $list), $values);
        };
        $beforeValues = $valuesOf($before);
        $afterValues = $valuesOf($after);
        $changes = [];
        foreach (array_unique([...array_keys($beforeValues), ...array_keys($afterValues)]) as $key) {
            if (($beforeValues[$key] ?? '') !== ($afterValues[$key] ?? '')) {
                $changes[] = ['key' => (string) $key, 'before' => $beforeValues[$key] ?? '', 'now' => $afterValues[$key] ?? '', 'shownInLines' => $shownInLines[$key]];
            }
        }

        return $changes;
    }

    /**
     * @param list<array{change: string, step: string, steps?: int, times?: int, movement?: string, parent?: string, changedInside?: bool, signature?: string}> $entries
     * @return list<array{change: string, step: string, steps?: int, times?: int, movement?: string, parent?: string, changedInside?: bool}>
     */
    private static function mergedRepeats(array $entries): array
    {
        $merged = [];
        foreach ($entries as $entry) {
            $last = array_key_last($merged);
            $repeats = $last !== null
                && $entry['change'] === FlowScenarioChange::MOVED
                && $merged[$last]['change'] === FlowScenarioChange::MOVED
                && ! $entry['changedInside'] && ! $merged[$last]['changedInside']
                && [$entry['signature'], $entry['movement'], $entry['parent']] === [$merged[$last]['signature'], $merged[$last]['movement'], $merged[$last]['parent']];
            if ($repeats) {
                $merged[$last]['times']++;
                continue;
            }
            $merged[] = $entry;
        }

        return array_map(function (array $entry): array {
            unset($entry['signature']);

            return $entry;
        }, $merged);
    }

    /**
     * @return list<array{0: int|null, 1: int|null}>
     */
    private static function aligned(int $beforeCount, int $afterCount, Closure $same): array
    {
        $common = array_fill(0, $beforeCount + 1, array_fill(0, $afterCount + 1, 0));
        $matches = [];
        for ($beforeLine = $beforeCount - 1; $beforeLine >= 0; $beforeLine--) {
            for ($afterLine = $afterCount - 1; $afterLine >= 0; $afterLine--) {
                $matches[$beforeLine][$afterLine] = $same($beforeLine, $afterLine);
                $common[$beforeLine][$afterLine] = $matches[$beforeLine][$afterLine]
                    ? $common[$beforeLine + 1][$afterLine + 1] + 1
                    : max($common[$beforeLine + 1][$afterLine], $common[$beforeLine][$afterLine + 1]);
            }
        }
        $aligned = [];
        $beforeLine = $afterLine = 0;
        while ($beforeLine < $beforeCount || $afterLine < $afterCount) {
            if ($beforeLine < $beforeCount && $afterLine < $afterCount && $matches[$beforeLine][$afterLine]) {
                $aligned[] = [$beforeLine++, $afterLine++];
            } elseif ($beforeLine < $beforeCount && ($afterLine === $afterCount || $common[$beforeLine + 1][$afterLine] >= $common[$beforeLine][$afterLine + 1])) {
                $aligned[] = [$beforeLine++, null];
            } else {
                $aligned[] = [null, $afterLine++];
            }
        }

        return $aligned;
    }
}
