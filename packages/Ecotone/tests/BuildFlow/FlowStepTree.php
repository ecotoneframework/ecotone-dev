<?php

declare(strict_types=1);

namespace Test\Ecotone\BuildFlow;

/**
 * licence Apache-2.0
 */
final class FlowStepTree
{
    private const DEEPEST_QUOTE = 6;
    private const STEPS_FOLDED_BY_SIZE = 6;
    private const UNCHANGED_STEPS_FOLDED = 4;
    private const ICONS = [
        'release collected message to' => '📨', 'entrypoint sends to' => '🚪', 'after successful send' => '🪝', 'take from channel' => '📬',
        'put on channel' => '📥', 'part report to' => '🧩', 'publish-subscribe' => '📣', 'direct channel' => '➡️', 'no hand-over' => '↩️',
        'before send' => '🪝', 'after send' => '🪝', 'after receive' => '🪝', 'before receive' => '🪝', 'forward to' => '🔀', 'route to' => '🔀',
        'record part' => '🧩', 'read part' => '🧩', 'release group' => '🧩', 'reply to' => '↩️', 'acknowledge' => '✅', 'dispatch' => '🧩',
        'receive' => '📬', 'gateway' => '🚪', 'handler' => '⚙️', 'router' => '🔀', 'around' => '🪝', 'before' => '🪝', 'after' => '🪝',
        'send' => '📨', 'span' => '🔎', 'open' => '🧩', 'load' => '💾', 'save' => '💾', 'begin' => '🔒', 'commit' => '🔒', 'deliver' => '📦', 'project' => '📊',
    ];
    private const SENDS = ['send', 'release collected message to'];
    private const CALLS = ['gateway', 'entrypoint sends to', 'forward to', 'save', 'deliver'];
    private const WRAPPING = ['around', 'span'];
    private const CHANGES = ['new', 'gone', 'rehinted'];

    /** @var list<int> */
    private array $end = [];
    /** @var list<?int> */
    private array $parent = [];
    /** @var list<int> */
    private array $children = [];
    /** @var list<bool> */
    private array $changedUnder = [];
    /** @var list<bool> */
    private array $onlyGoneUnder = [];
    private int $roots = 0;

    /**
     * @param list<array{mark: string, status: string, depth: int, depthBefore?: int, label: string, hints: list<array{key: string, value: string}>, hintsBefore?: list<array{key: string, value: string}>}> $rows
     */
    private function __construct(private array $rows)
    {
        $count = count($rows);
        $stack = [];
        $this->children = array_fill(0, $count, 0);
        foreach ($rows as $position => $row) {
            $stack = array_filter($stack, fn (int $depth): bool => $depth < $row['depth'], ARRAY_FILTER_USE_KEY);
            $this->parent[$position] = $stack[$row['depth'] - 1] ?? null;
            $stack[$row['depth']] = $position;
            if ($this->parent[$position] === null) {
                $this->roots++;
            } else {
                $this->children[$this->parent[$position]]++;
            }
        }
        for ($position = 0; $position < $count; $position++) {
            $end = $position + 1;
            while ($end < $count && $rows[$end]['depth'] > $rows[$position]['depth']) {
                $end++;
            }
            $this->end[$position] = $end;
            $statuses = array_map(fn (array $row): string => $row['status'], array_slice($rows, $position, $end - $position));
            $changed = array_values(array_filter($statuses, fn (string $status): bool => in_array($status, self::CHANGES, true)));
            $this->changedUnder[$position] = $changed !== [];
            $this->onlyGoneUnder[$position] = $changed !== [] && array_unique($changed) === ['gone'];
        }
    }

    /**
     * @param list<array{mark: string, status: string, depth: int, depthBefore?: int, label: string, hints: list<array{key: string, value: string}>, hintsBefore?: list<array{key: string, value: string}>}> $rows
     */
    public static function of(array $rows, bool $foldsUnchangedRuns): string
    {
        if ($rows === []) {
            return '';
        }
        $tree = new self($rows);

        return self::rendered($tree->level(from: 0, to: count($rows), base: min(array_column($rows, 'depth')), quotes: 0, foldingRuns: $foldsUnchangedRuns));
    }

    private static function iconOf(string $label): string
    {
        $kind = self::kindOf($label);

        return $kind === '' ? '' : self::ICONS[$kind];
    }

    private static function kindOf(string $label): string
    {
        foreach (array_keys(self::ICONS) as $kind) {
            if ($label === $kind || str_starts_with($label, $kind . ' ')) {
                return $kind;
            }
        }

        return '';
    }

    private static function withIcon(string $label): string
    {
        $icon = self::iconOf($label);

        return ($icon === '' ? '' : $icon . ' ') . $label;
    }

    /**
     * @return list<array{0: string, 1: list<string>|string}>
     */
    private function level(int $from, int $to, int $base, int $quotes, bool $foldingRuns): array
    {
        $items = [];
        $position = $from;
        while ($position < $to) {
            if ($foldingRuns && ! $this->changedUnder[$position] && ! $this->foldsOnItsOwn($position, $quotes)) {
                $runEnd = $position;
                while ($runEnd < $to && ! $this->changedUnder[$runEnd] && ! $this->foldsOnItsOwn($runEnd, $quotes)) {
                    $runEnd = $this->end[$runEnd];
                }
                $count = $runEnd - $position;
                if ($count < self::UNCHANGED_STEPS_FOLDED) {
                    self::addLines($items, $this->linesOf(from: $position, to: $runEnd, base: $base));
                } else {
                    $inner = self::rendered($this->level(from: $position, to: $runEnd, base: $quotes < self::DEEPEST_QUOTE ? $this->rows[$position]['depth'] : $base, quotes: $quotes + 1, foldingRuns: false));
                    $items[] = ['html', self::fold(open: false, summary: '… ' . $count . ' unchanged steps', inner: self::quoted($inner, $quotes))];
                }
                $position = $runEnd;
                continue;
            }
            $label = $this->rows[$position]['label'];
            $kind = self::kindOf($label);
            $inside = $this->end[$position] - $position - 1;
            $chain = $this->wrappingChainOf($position);
            if (in_array($kind, self::SENDS, true) && $inside > 0) {
                $items[] = ['html', $this->foldOfSteps(position: $position, base: $base, foldingRuns: $foldingRuns, summary: '<code>' . htmlspecialchars(self::withIcon($label)) . '</code> · ' . self::steps($inside) . ' of channel interceptors', quotes: $quotes)];
            } elseif (in_array($kind, self::CALLS, true) && $inside > 0) {
                $items[] = ['html', $this->foldOfSteps(position: $position, base: $base, foldingRuns: $foldingRuns, summary: '<code>' . htmlspecialchars(self::withIcon($label)) . '</code> · ' . self::steps($inside) . ' inside', quotes: $quotes)];
            } elseif ($kind === 'around' && $chain !== null) {
                $last = $chain[count($chain) - 1];
                $wrapped = $this->childrenOf($last);
                $changed = array_filter($chain, fn (int $member): bool => in_array($this->rows[$member]['status'], self::CHANGES, true));
                $listed = [];
                foreach ($chain as $member) {
                    array_push($listed, ...$this->linesOf(from: $member, to: $member + 1, base: $quotes < self::DEEPEST_QUOTE ? $this->rows[$position]['depth'] : $base));
                }
                $around = count($wrapped) === 1
                    ? '<code>' . htmlspecialchars(self::withIcon($this->rows[$wrapped[0]]['label'])) . '</code>'
                    : 'the next ' . self::steps($this->end[$last] - $last - 1) . ', through <code>' . htmlspecialchars(self::withIcon($this->rows[$wrapped[count($wrapped) - 1]]['label'])) . '</code>';
                $summary = '🪝 ' . $this->aroundsIn($chain) . ' interceptors around ' . $around;
                $items[] = ['html', self::fold(open: $changed !== [], summary: self::dotted(summary: $summary, status: $this->rows[$position]['status'], changed: $changed !== [], onlyGone: $changed !== [] && array_unique(array_map(fn (int $member): string => $this->rows[$member]['status'], $changed)) === ['gone']), inner: self::quoted(self::fenced($listed), $quotes))];
                foreach ($this->level(from: $last + 1, to: $this->end[$last], base: $base + count($chain), quotes: $quotes, foldingRuns: $foldingRuns) as $item) {
                    self::addItem($items, $item);
                }
            } elseif ($this->foldsBySize($position, $quotes)) {
                $items[] = ['html', $this->foldOfSteps(position: $position, base: $base, foldingRuns: $foldingRuns, summary: '<code>' . htmlspecialchars(self::withIcon($label)) . '</code> · ' . self::steps($inside) . ' inside', quotes: $quotes)];
            } else {
                self::addLines($items, $this->linesOf(from: $position, to: $position + 1, base: $base));
                $under = $this->level(from: $position + 1, to: $this->end[$position], base: $base, quotes: $quotes, foldingRuns: $foldingRuns);
                $folds = array_filter($under, fn (array $item): bool => $item[0] === 'html') !== [];
                if ($this->children[$position] > 1 && $folds && $quotes < self::DEEPEST_QUOTE) {
                    $items[] = ['html', self::quoted(self::rendered($this->level(from: $position + 1, to: $this->end[$position], base: $this->rows[$position]['depth'] + 1, quotes: $quotes + 1, foldingRuns: $foldingRuns)), $quotes)];
                } else {
                    foreach ($under as $item) {
                        self::addItem($items, $item);
                    }
                }
            }
            $position = $this->end[$position];
        }

        return $items;
    }

    private function foldOfSteps(int $position, int $base, bool $foldingRuns, string $summary, int $quotes): string
    {
        $quoted = $quotes < self::DEEPEST_QUOTE;
        $items = [];
        self::addLines($items, array_slice($this->linesOf(from: $position, to: $position + 1, base: $quoted ? $this->rows[$position]['depth'] : $base), 1));
        foreach ($this->level(from: $position + 1, to: $this->end[$position], base: $quoted ? $this->rows[$position]['depth'] + 1 : $base, quotes: $quotes + 1, foldingRuns: $foldingRuns && $this->changedUnder[$position]) as $item) {
            self::addItem($items, $item);
        }

        return self::fold(
            open: $this->changedUnder[$position],
            summary: self::dotted(summary: $summary, status: $this->rows[$position]['status'], changed: $this->changedUnder[$position], onlyGone: $this->onlyGoneUnder[$position]),
            inner: self::quoted(self::rendered($items), $quotes),
        );
    }

    private function foldsOnItsOwn(int $position, int $quotes): bool
    {
        $kind = self::kindOf($this->rows[$position]['label']);
        $inside = $this->end[$position] - $position - 1;

        return ($inside > 0 && in_array($kind, [...self::SENDS, ...self::CALLS], true))
            || ($kind === 'around' && $this->wrappingChainOf($position) !== null)
            || $this->foldsBySize($position, $quotes);
    }

    private function foldsBySize(int $position, int $quotes): bool
    {
        $parent = $this->parent[$position];

        return $quotes < self::DEEPEST_QUOTE
            && $this->end[$position] - $position - 1 >= self::STEPS_FOLDED_BY_SIZE
            && ($parent === null ? $this->roots : $this->children[$parent]) > 1;
    }

    /**
     * @return list<int>|null
     */
    private function wrappingChainOf(int $position): ?array
    {
        $chain = [$position];
        $current = $position;
        while ($this->children[$current] === 1 && in_array(self::kindOf($this->rows[$current + 1]['label']), self::WRAPPING, true)) {
            $current++;
            $chain[] = $current;
        }

        return $this->aroundsIn($chain) >= 2 ? $chain : null;
    }

    /**
     * @param list<int> $chain
     */
    private function aroundsIn(array $chain): int
    {
        return count(array_filter($chain, fn (int $member): bool => self::kindOf($this->rows[$member]['label']) === 'around'));
    }

    /**
     * @return list<int>
     */
    private function childrenOf(int $position): array
    {
        $children = [];
        for ($child = $position + 1; $child < $this->end[$position]; $child = $this->end[$child]) {
            $children[] = $child;
        }

        return $children;
    }

    /**
     * @return list<string>
     */
    private function linesOf(int $from, int $to, int $base): array
    {
        $lines = [];
        for ($position = $from; $position < $to; $position++) {
            $row = $this->rows[$position];
            $indent = str_repeat('  ', max(0, $row['depth'] - $base));
            $lines[] = $row['mark'] . ' ' . $indent . self::withIcon($row['label']);
            $hint = fn (string $mark, array $hint): string => $mark . ' ' . $indent . '  ↳ ' . $hint['key'] . ': ' . $hint['value'];
            if (! isset($row['hintsBefore'])) {
                foreach ($row['hints'] as $shown) {
                    $lines[] = $hint($row['mark'], $shown);
                }
                continue;
            }
            foreach ($row['hintsBefore'] as $shown) {
                $lines[] = $hint(in_array($shown, $row['hints'], true) ? ' ' : '-', $shown);
            }
            foreach ($row['hints'] as $shown) {
                if (! in_array($shown, $row['hintsBefore'], true)) {
                    $lines[] = $hint('+', $shown);
                }
            }
        }

        return $lines;
    }

    public static function steps(int $count): string
    {
        return $count . ($count === 1 ? ' step' : ' steps');
    }

    private static function dotted(string $summary, string $status, bool $changed, bool $onlyGone): string
    {
        return match (true) {
            $status === 'new' => '🟢 new · ' . $summary,
            $status === 'gone' => '🔴 gone · ' . $summary,
            ! $changed => $summary,
            default => ($onlyGone ? '🔴 ' : '🟢 ') . $summary,
        };
    }

    public static function fold(bool $open, string $summary, string $inner): string
    {
        return '<details' . ($open ? ' open' : '') . ">\n<summary>" . $summary . "</summary>\n\n" . $inner . "\n\n</details>";
    }

    private static function quoted(string $inner, int $quotes): string
    {
        return $quotes < self::DEEPEST_QUOTE ? "<blockquote>\n\n" . $inner . "\n\n</blockquote>" : $inner;
    }

    /**
     * @param list<string> $lines
     */
    private static function fenced(array $lines): string
    {
        return "```diff\n" . implode("\n", $lines) . "\n```";
    }

    /**
     * @param list<array{0: string, 1: list<string>|string}> $items
     * @param list<string> $lines
     */
    private static function addLines(array &$items, array $lines): void
    {
        if ($lines === []) {
            return;
        }
        self::addItem($items, ['lines', $lines]);
    }

    /**
     * @param list<array{0: string, 1: list<string>|string}> $items
     * @param array{0: string, 1: list<string>|string} $item
     */
    private static function addItem(array &$items, array $item): void
    {
        $last = count($items) - 1;
        if ($item[0] === 'lines' && $last >= 0 && $items[$last][0] === 'lines') {
            $items[$last][1] = [...$items[$last][1], ...$item[1]];

            return;
        }
        $items[] = $item;
    }

    /**
     * @param list<array{0: string, 1: list<string>|string}> $items
     */
    private static function rendered(array $items): string
    {
        return implode("\n\n", array_map(fn (array $item): string => $item[0] === 'lines' ? self::fenced($item[1]) : $item[1], $items));
    }
}
