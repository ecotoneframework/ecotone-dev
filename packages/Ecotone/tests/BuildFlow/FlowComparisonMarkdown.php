<?php

declare(strict_types=1);

namespace Test\Ecotone\BuildFlow;

use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowEdgeChange;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeChange;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Tracing\Execution\RecordedExecution;

/**
 * licence Apache-2.0
 */
final class FlowComparisonMarkdown
{
    public const BEFORE_AFTER_PAIR = 'before-after';
    public const MARKED_DIAGRAM = 'marked';
    public const MESSAGE_PATH = 'path';
    public const BUILD_VIEW = 'build';
    private const STEPS_PER_LIST = 40;
    private const SCENARIOS_PER_LINE = 6;
    private const EMPTY_FLOWCHART = "flowchart LR\n";
    private const DERIVED_STEP = ' (from the wiring: no span is recorded)';

    public static function of(FlowSnapshotComparison $comparison, string $presentation): string
    {
        if (! in_array($presentation, [self::BEFORE_AFTER_PAIR, self::MARKED_DIAGRAM, self::MESSAGE_PATH, self::BUILD_VIEW], true)) {
            throw InvalidArgumentException::create("ecotone:build-flow:compare was asked for presentation `{$presentation}`, which it cannot produce. Pass --presentation=before-after (the default) for a before/after pair of diagrams and step lists, --presentation=marked for one diagram with the changes marked and one step list, --presentation=path for the message's path as a flow diagram with what changed in sentences, --presentation=build for the build, the message's path and the marked wiring collapsed, or --presentation=json for the raw comparison.");
        }
        $title = "## Message flow: what changed\n\n";
        if ($comparison->groups === [] && $comparison->everyScenario === null) {
            return $title . ($comparison->scenariosCompared === 1 ? "The 1 scenario did not change its wiring, its build or its traced spans.\n" : "None of {$comparison->scenariosCompared} scenarios changed its wiring, its build or its traced spans.\n");
        }
        $changesItsPath = fn (array $group): bool => ! $group['change']->runsTheSameSteps() && ($group['change']->state !== FlowScenarioChange::CHANGED || $group['change']->wiring->hasChanges() || $group['change']->hasTraceChanges() || $group['change']->hasExecutionChanges());
        $pathGroups = array_values(array_filter($comparison->groups, fn (array $group): bool => $changesItsPath($group) || $group['change']->runsTheSameSteps()));
        $count = fn (array $groups): int => array_sum(array_map(fn (array $group): int => count($group['scenarios']), $groups));
        $withPath = $count(array_filter($comparison->groups, $changesItsPath));
        $sameSteps = $count(array_filter($comparison->groups, fn (array $group): bool => $group['change']->runsTheSameSteps()));
        $onlyBuild = $count($comparison->groups) - $withPath - $sameSteps;
        $forEveryScenario = count($comparison->everyScenario?->build ?? []);
        $summary = implode(' ', array_filter([
            $withPath === 0
                ? "No scenario of {$comparison->scenariosCompared} changes its message path."
                : "{$withPath} of {$comparison->scenariosCompared} scenario" . ($comparison->scenariosCompared === 1 ? '' : 's') . ' change' . ($withPath === 1 ? 's its' : ' their') . ' message path.',
            $sameSteps === 0 ? null : $sameSteps . ($withPath === 0 ? '' : ' more') . ($sameSteps === 1 ? ' runs' : ' run') . ' the same steps with other hints.',
            $onlyBuild === 0 ? null : $onlyBuild . ($withPath + $sameSteps === 0 ? '' : ' more') . ' change' . ($onlyBuild === 1 ? 's only its' : ' only their') . ' build.',
            $forEveryScenario === 0 ? null : $forEveryScenario . ' registration' . ($forEveryScenario === 1 ? ' changes' : 's change') . ' for every scenario.',
        ]));
        $sections = array_map(
            fn (array $group): string => self::headingOf($group['scenarios'], $group['varyingNames'])
                . implode("\n\n", $group['change']->summary()) . "\n\n"
                . match ($presentation) {
                    self::BEFORE_AFTER_PAIR => self::beforeAfterPair($group['change']),
                    self::MARKED_DIAGRAM => self::markedDiagram($group['change']),
                    self::MESSAGE_PATH => self::messagePath($group['change']),
                    default => self::stepsOfEveryProcess($group['change']) . self::messagePath($group['change']) . "\n<details><summary>Marked wiring and traced spans</summary>\n\n" . self::markedDiagram($group['change']) . "\n</details>\n",
                }
            . ($presentation === self::BUILD_VIEW ? '' : self::whatRan($group['change'])),
            $pathGroups,
        );
        $tree = FlowBuildTree::of($comparison);

        $changedScenarios = $presentation === self::BUILD_VIEW ? self::changedScenariosOf($pathGroups) : '';

        return $title . $summary . "\n\n" . $changedScenarios . implode("\n", array_filter([$tree, ...$sections]));
    }

    /**
     * @param list<array{scenarios: list<string>, change: FlowScenarioChange}> $groups
     */
    private static function changedScenariosOf(array $groups): string
    {
        $lines = [];
        foreach ($groups as $group) {
            $change = $group['change'];
            $processes = $change->execution === [] ? array_fill(0, count($change->after->execution), ['change' => FlowScenarioChange::UNCHANGED]) : $change->execution;
            $changed = count(array_filter($processes, fn (array $process): bool => $process['change'] !== FlowScenarioChange::UNCHANGED));
            $what = $changed > 0
                ? $changed . ' of ' . count($processes) . ' process' . (count($processes) === 1 ? '' : 'es') . ' changed'
                : implode(' and ', array_filter([$change->wiring->hasChanges() ? 'wiring' : '', $change->hasTraceChanges() ? 'traced spans' : ''])) . ' only';
            foreach ($group['scenarios'] as $scenario) {
                $lines[] = '- ' . self::code($scenario) . ': ' . $what;
            }
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n\n";
    }

    private static function stepsOfEveryProcess(FlowScenarioChange $change): string
    {
        if (! $change->hasExecutionChanges()) {
            return '';
        }
        $after = $change->after->execution;
        $before = $change->before->execution;
        $notes = [];
        foreach ($change->execution as $process) {
            if ($process['change'] === FlowScenarioChange::CHANGED && ($process['steps'] ?? []) !== []) {
                $notes[$process['process']] = ucfirst(FlowWording::ofChangedProcess($process['steps']));
            }
        }
        $beforeByTitle = array_column($before, null, 'process');
        $afterTitles = array_column($after, 'process');
        $blocks = [];
        $stepsOf = fn (int $number, array $rows, bool $changed, string $side = ''): string => "\n\n" . FlowStepTree::fold(open: $changed, summary: 'Steps of process ' . $number . $side, inner: FlowStepTree::of(rows: $rows, foldsUnchangedRuns: $changed));
        foreach ($after as $position => $process) {
            $number = $position + 1;
            $heading = (isset($process['sentBy']) ? '**Sub-flow · process ' . $number . ' of ' . count($after) . '** · sent by process ' . $process['sentBy']['processNumber'] : '**Flow · process ' . $number . ' of ' . count($after) . '**');
            $earlier = $beforeByTitle[$process['process']] ?? null;
            if ($earlier === null) {
                $rows = array_map(fn (array $step): array => ['mark' => '+', 'status' => 'new', 'depth' => $step['depth'], 'label' => $step['label'], 'hints' => self::shownHintsOf($step)], $process['steps']);
                $blocks[] = $heading . ' · runs only now · ' . FlowStepTree::steps(count($process['steps'])) . "\n" . self::code($process['process']) . $stepsOf(number: $number, rows: $rows, changed: true);
                continue;
            }
            $rows = self::rowsOfDiff(before: $earlier, after: $process, beforeTitles: array_column($before, 'process'), afterTitles: $afterTitles);
            $counts = array_count_values(array_map(fn (array $row): string => $row['status'], $rows));
            $status = implode(', ', array_filter([
                self::counted(count: $counts['new'] ?? 0, one: 'new step', many: 'new steps'),
                self::counted(count: $counts['gone'] ?? 0, one: 'step gone', many: 'steps gone'),
            ])) ?: implode(', ', array_filter([
                self::counted(count: $counts['rehinted'] ?? 0, one: 'step with other hints', many: 'steps with other hints'),
                self::counted(count: $counts['moved'] ?? 0, one: 'step moved', many: 'steps moved'),
            ]));
            $stepCount = count($earlier['steps']) === count($process['steps']) ? FlowStepTree::steps(count($process['steps'])) : count($earlier['steps']) . ' → ' . count($process['steps']) . ' steps';
            if ($status === '') {
                $blocks[] = $heading . ' · unchanged · ' . $stepCount . "\n" . self::code($process['process']) . $stepsOf(number: $number, rows: $rows, changed: false);
                continue;
            }
            $note = self::noteOfChangedSteps($rows) ?? $notes[$process['process']] ?? null;
            $blocks[] = $heading . ' · ' . $status . ' · ' . $stepCount . "\n" . self::code($process['process']) . ($note !== null ? "\n" . $note : '') . $stepsOf(number: $number, rows: $rows, changed: true);
        }
        $noLongerRunning = [];
        $lastStillRunning = -1;
        foreach ($before as $position => $process) {
            if (in_array($process['process'], $afterTitles, true)) {
                $lastStillRunning = (int) array_search($process['process'], $afterTitles, true);
                continue;
            }
            $rows = array_map(fn (array $step): array => ['mark' => '-', 'status' => 'gone', 'depth' => $step['depth'], 'label' => $step['label'], 'hints' => self::shownHintsOf($step)], $process['steps']);
            $noLongerRunning[$lastStillRunning][] = '**' . (isset($process['sentBy']) ? 'Sub-flow' : 'Flow') . ' · process ' . ($position + 1) . ' of ' . count($before) . ' before** · no longer runs · ' . FlowStepTree::steps(count($process['steps'])) . "\n" . self::code($process['process']) . $stepsOf(number: $position + 1, rows: $rows, changed: true, side: ' before');
        }

        $inRunOrder = $noLongerRunning[-1] ?? [];
        foreach ($blocks as $position => $block) {
            array_push($inRunOrder, $block, ...($noLongerRunning[$position] ?? []));
        }

        return "#### What ran, process by process\n\n🟢 a fold holding something new or changed, open; 🔴 a fold holding only gone steps, open; `🟢 new ·` / `🔴 gone ·` the folded step itself is new / gone; `+` new, `-` gone; each bar is one level deeper, and past six bars the indentation shows the depth.\n\n" . implode("\n\n", $inRunOrder) . "\n\n";
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function noteOfChangedSteps(array $rows): ?string
    {
        $afterParent = [];
        $beforeParent = [];
        $afterStack = [];
        $beforeStack = [];
        foreach ($rows as $position => $row) {
            if ($row['status'] !== 'gone') {
                $afterParent[$position] = $afterStack[$row['depth'] - 1] ?? null;
                $afterStack[$row['depth']] = $position;
                $afterStack = array_slice($afterStack, 0, $row['depth'] + 1, true);
            }
            if ($row['status'] !== 'new') {
                $depth = $row['depthBefore'] ?? $row['depth'];
                $beforeParent[$position] = $beforeStack[$depth - 1] ?? null;
                $beforeStack[$depth] = $position;
                $beforeStack = array_slice($beforeStack, 0, $depth + 1, true);
            }
        }
        $statusOf = fn (?int $position): ?string => $position === null ? null : $rows[$position]['status'];
        $isTop = fn (int $position): bool => match ($rows[$position]['status']) {
            'new' => $statusOf($afterParent[$position]) !== 'new',
            'gone' => $statusOf($beforeParent[$position]) !== 'gone',
            default => false,
        };
        $news = [];
        $newLabels = [];
        $gone = [];
        $paired = [];
        foreach ($rows as $position => $row) {
            if (! $isTop($position)) {
                continue;
            }
            if ($row['status'] === 'gone') {
                $gone[$position] = $row['label'];
                continue;
            }
            $replaced = null;
            for ($earlier = $position - 1; $earlier >= 0; $earlier--) {
                if (! in_array($rows[$earlier]['status'], ['new', 'gone'], true)) {
                    break;
                }
                if ($isTop($earlier)) {
                    $replaced = $rows[$earlier]['status'] === 'gone' && ($rows[$earlier]['depthBefore'] ?? $rows[$earlier]['depth']) === $row['depth'] && ! isset($paired[$earlier]) ? $earlier : null;
                    break;
                }
            }
            if ($replaced !== null) {
                $paired[$replaced] = true;
            }
            $news[] = self::code($row['label']) . ($replaced !== null ? ' in place of ' . self::code($rows[$replaced]['label']) : '');
            $newLabels[] = $row['label'];
        }
        $gone = array_diff_key($gone, $paired);
        $moved = array_keys(array_filter($rows, fn (array $row): bool => isset($row['depthBefore']) && $row['depthBefore'] !== $row['depth']));
        $movedUnder = [];
        $movedCount = [];
        foreach ($moved as $position) {
            if (in_array($afterParent[$position], $moved, true)) {
                continue;
            }
            $anchor = $afterParent[$position];
            while ($anchor !== null && $rows[$anchor]['status'] !== 'new') {
                $anchor = $afterParent[$anchor];
            }
            $under = $anchor !== null ? 'the new ' . self::code($rows[$anchor]['label']) : ($afterParent[$position] !== null ? self::code($rows[$afterParent[$position]]['label']) : 'no other step');
            $label = $rows[$position]['label'];
            $movedUnder[$under][$label] = ($movedUnder[$under][$label] ?? 0) + 1;
            $movedCount[$under] = ($movedCount[$under] ?? 0) + count(array_filter($moved, fn (int $inside): bool => $inside >= $position && self::isUnder(rows: $rows, step: $inside, top: $position)));
        }
        $onlyNew = count($news) === 1 && count($newLabels) === 1 ? 'the new ' . self::code($newLabels[0]) : null;
        $sentences = array_filter([
            $news === [] ? '' : 'New: ' . self::listed($news) . '.',
            $gone === [] ? '' : 'Gone: ' . self::listed(array_map(fn (string $label): string => self::code($label), array_values($gone))) . '.',
            ...array_map(
                fn (string $under, array $tops): string => implode(', ', array_map(fn (string $label, int $times): string => ($times > 1 ? $times . ' × ' : '') . self::code($label), array_keys($tops), $tops))
                    . ' (' . FlowStepTree::steps($movedCount[$under]) . ' with what runs inside ' . (array_sum($tops) === 1 ? 'it' : 'them') . ') now ' . (array_sum($tops) === 1 ? 'runs' : 'run') . ' under ' . ($under === $onlyNew ? 'it' : $under) . '.',
                array_keys($movedUnder),
                $movedUnder,
            ),
        ]);

        return $sentences === [] ? null : implode(' ', $sentences);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function isUnder(array $rows, int $step, int $top): bool
    {
        for ($position = $top + 1; $position <= $step; $position++) {
            if ($rows[$position]['depth'] <= $rows[$top]['depth']) {
                return false;
            }
        }

        return true;
    }

    public static function code(string $text): string
    {
        preg_match_all('/`+/', $text, $runs);
        $fence = str_repeat('`', max([0, ...array_map('strlen', $runs[0])]) + 1);
        $padding = str_starts_with($text, '`') || str_ends_with($text, '`') ? ' ' : '';

        return $fence . $padding . $text . $padding . $fence;
    }

    /**
     * @param list<string> $items
     */
    private static function listed(array $items): string
    {
        return count($items) === 1 ? $items[0] : implode(', ', array_slice($items, 0, -1)) . ', and ' . $items[count($items) - 1];
    }

    private static function counted(int $count, string $one, string $many): string
    {
        return $count === 0 ? '' : $count . ' ' . ($count === 1 ? $one : $many);
    }

    /**
     * @param array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>} $step
     * @return list<array{key: string, value: string}>
     */
    private static function shownHintsOf(array $step): array
    {
        return array_values(array_map(fn (array $hint): array => ['key' => $hint['key'], 'value' => $hint['value']], array_filter($step['hints'], fn (array $hint): bool => $hint['shownInLines'])));
    }

    /**
     * @param array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>} $before
     * @param array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>} $after
     * @param list<string> $beforeTitles
     * @param list<string> $afterTitles
     * @return list<array{mark: string, status: string, depth: int, depthBefore?: int, label: string, hints: list<array{key: string, value: string}>, hintsBefore?: list<array{key: string, value: string}>}>
     */
    private static function rowsOfDiff(array $before, array $after, array $beforeTitles, array $afterTitles): array
    {
        $old = $before['steps'];
        $new = $after['steps'];
        $titled = fn (array $hints, array $titles): array => array_map(fn (array $hint): array => [...$hint, 'value' => (string) preg_replace_callback('/\bprocess (\d+)\b/', fn (array $number): string => 'process `' . ($titles[(int) $number[1] - 1] ?? $number[1]) . '`', $hint['value'])], $hints);
        $lengths = array_fill(0, count($old) + 1, array_fill(0, count($new) + 1, 0));
        for ($i = count($old) - 1; $i >= 0; $i--) {
            for ($j = count($new) - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $old[$i]['label'] === $new[$j]['label'] ? $lengths[$i + 1][$j + 1] + 1 : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }
        $rows = [];
        $i = 0;
        $j = 0;
        while ($i < count($old) || $j < count($new)) {
            if ($i < count($old) && $j < count($new) && $old[$i]['label'] === $new[$j]['label']) {
                $hintsBefore = self::shownHintsOf($old[$i]);
                $hints = self::shownHintsOf($new[$j]);
                $rehinted = $titled($hintsBefore, $beforeTitles) !== $titled($hints, $afterTitles);
                $rows[] = ['mark' => ' ', 'status' => $rehinted ? 'rehinted' : ($old[$i]['depth'] !== $new[$j]['depth'] ? 'moved' : 'same'), 'depth' => $new[$j]['depth'], 'depthBefore' => $old[$i]['depth'], 'label' => $new[$j]['label'], 'hints' => $hints, ...($rehinted ? ['hintsBefore' => $hintsBefore] : [])];
                $i++;
                $j++;
            } elseif ($j >= count($new) || ($i < count($old) && $lengths[$i + 1][$j] >= $lengths[$i][$j + 1])) {
                $rows[] = ['mark' => '-', 'status' => 'gone', 'depth' => $old[$i]['depth'], 'label' => $old[$i]['label'], 'hints' => self::shownHintsOf($old[$i])];
                $i++;
            } else {
                $rows[] = ['mark' => '+', 'status' => 'new', 'depth' => $new[$j]['depth'], 'label' => $new[$j]['label'], 'hints' => self::shownHintsOf($new[$j])];
                $j++;
            }
        }

        return $rows;
    }

    private static function whatRan(FlowScenarioChange $change): string
    {
        if (! $change->hasExecutionChanges()) {
            return '';
        }
        $sections = [];
        $printedAlone = [];
        foreach (self::inFlowOrder($change->execution) as $process) {
            $state = match (true) {
                $process['change'] === FlowScenarioChange::UNCHANGED => 'unchanged.',
                $process['change'] === FlowScenarioChange::ADDED => 'runs only now.',
                $process['change'] === FlowScenarioChange::REMOVED => 'ran only before.',
                $process['steps'] === [] => 'runs the same steps.',
                default => FlowWording::ofChangedProcess($process['steps']),
            };
            $entries = $process['steps'] ?? [];
            if ($process['change'] === FlowScenarioChange::ADDED) {
                $entries = self::withSharedPreambleLeftOut($entries, array_values(array_filter($change->after->execution, fn (array $other): bool => in_array($other['process'], array_column($change->before->execution, 'process'), true))));
            }
            $lines = [];
            foreach ($entries as $entry) {
                array_push($lines, ...self::linesOfStep($entry));
            }
            $alike = $lines === [] ? null : self::alikeOf($lines, $printedAlone[$process['change']] ?? []);
            if ($alike !== null) {
                $lines = array_values(array_diff_assoc($lines, $alike['lines']));
                $state = ($process['change'] === FlowScenarioChange::ADDED ? 'runs only now' : 'runs other steps now')
                    . ($lines === [] ? ', with the same steps as `' . $alike['process'] . '`.' : ', like `' . $alike['process'] . '` except:');
            } elseif ($lines !== []) {
                $printedAlone[$process['change']][] = ['process' => $process['process'], 'lines' => $lines];
            }
            $sections[] = 'Process `' . $process['process'] . '`' . (isset($process['sentBy']) ? ', a sub-flow of `' . $process['sentBy'] . '`' : '') . ': ' . $state . ($lines === [] ? '' : "\n\n```diff\n" . implode("\n", $lines) . "\n```");
        }
        $subFlows = FlowWording::ofSubFlowChanges($change->execution);

        return "\n#### What ran, process by process\n\n" . ($subFlows === '' ? '' : $subFlows . "\n\n") . implode("\n\n", $sections) . "\n";
    }

    /**
     * @param list<array{process: string, change: string, sentBy?: string, sentAt?: int, sentByBefore?: ?string, steps?: list<array<string, mixed>>}> $execution
     * @return list<array{process: string, change: string, sentBy?: string, sentAt?: int, sentByBefore?: ?string, steps?: list<array<string, mixed>>}>
     */
    private static function inFlowOrder(array $execution): array
    {
        $subFlows = [];
        $flows = [];
        foreach ($execution as $position => $process) {
            $sender = isset($process['sentBy']) ? self::senderPositionOf($execution, $process) : false;
            if ($sender === false || $sender === $position) {
                $flows[] = $position;
                continue;
            }
            $subFlows[$sender][] = $position;
        }
        $ordered = [];
        $visit = function (int $position) use (&$visit, &$ordered, $execution, $subFlows): void {
            if (isset($ordered[$position])) {
                return;
            }
            $ordered[$position] = $execution[$position];
            $children = $subFlows[$position] ?? [];
            usort($children, fn (int $first, int $second): int => [$execution[$first]['sentAt'] ?? 0, $first] <=> [$execution[$second]['sentAt'] ?? 0, $second]);
            foreach ($children as $child) {
                $visit($child);
            }
        };
        foreach ($flows as $flow) {
            $visit($flow);
        }
        foreach (array_keys($execution) as $position) {
            $visit($position);
        }

        return array_values($ordered);
    }

    /**
     * @param list<array{process: string, change: string, sentBy?: string}> $execution
     * @param array{process: string, change: string, sentBy?: string} $subFlow
     */
    private static function senderPositionOf(array $execution, array $subFlow): int|false
    {
        $otherSide = $subFlow['change'] === FlowScenarioChange::REMOVED ? FlowScenarioChange::ADDED : FlowScenarioChange::REMOVED;
        foreach ($execution as $position => $process) {
            if ($process['process'] === ($subFlow['sentBy'] ?? null) && $process['change'] !== $otherSide) {
                return $position;
            }
        }

        return false;
    }

    /**
     * @param list<string> $lines
     * @param list<array{process: string, lines: list<string>}> $printed
     * @return array{process: string, lines: list<string>}|null
     */
    private static function alikeOf(array $lines, array $printed): ?array
    {
        $withoutNumbers = fn (array $of): array => array_map(fn (string $line): string => (string) preg_replace('/\d+/', '#', $line), $of);
        foreach ($printed as $earlier) {
            if (count($earlier['lines']) === count($lines) && $withoutNumbers($earlier['lines']) === $withoutNumbers($lines)) {
                return $earlier;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>}> $after
     * @return list<array<string, mixed>>
     */
    private static function withSharedPreambleLeftOut(array $entries, array $after): array
    {
        $keyOf = fn (string $step, array $hints): string => $step . json_encode(array_values(array_filter($hints, fn (array $hint): bool => $hint['shownInLines'])), JSON_THROW_ON_ERROR);
        $best = ['process' => '', 'steps' => 0];
        foreach ($after as $other) {
            $shared = 0;
            while (isset($entries[$shared + 1], $other['steps'][$shared + 1])
                && $keyOf((string) $entries[$shared + 1]['step'], $entries[$shared + 1]['hints'] ?? []) === $keyOf(str_repeat('  ', $other['steps'][$shared + 1]['depth']) . $other['steps'][$shared + 1]['label'], $other['steps'][$shared + 1]['hints'])) {
                $shared++;
            }
            if ($shared > $best['steps']) {
                $best = ['process' => $other['process'], 'steps' => $shared];
            }
        }
        if ($best['steps'] < 3) {
            return $entries;
        }

        return [$entries[0], ['change' => FlowScenarioChange::LEFT_OUT, 'steps' => $best['steps'], 'asIn' => $best['process']], ...array_slice($entries, 1 + $best['steps'])];
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private static function linesOfStep(array $entry): array
    {
        $depth = intdiv(strspn((string) ($entry['step'] ?? ''), ' '), 2);
        $prefix = match ($entry['change']) {
            FlowScenarioChange::ADDED => '+ ',
            FlowScenarioChange::REMOVED => '- ',
            default => '  ',
        };
        $lines = [match ($entry['change']) {
            FlowScenarioChange::LEFT_OUT => '  … ' . $entry['steps'] . (isset($entry['asIn']) ? ' steps as in `' . $entry['asIn'] . '`' : ' unchanged steps'),
            FlowScenarioChange::MOVED => '  ' . FlowWording::ofMovedSteps($entry),
            default => $prefix . $entry['step'],
        }];
        foreach ($entry['hints'] ?? [] as $hint) {
            $lines[] = $prefix . RecordedExecution::hintLine(depth: $depth, key: $hint['key'], value: $hint['value']);
        }
        $details = [];
        foreach ($entry['hintChanges'] ?? [] as $change) {
            if (! ($change['shownInLines'] ?? true)) {
                $details[] = $change['key'];
                continue;
            }
            if ($change['before'] !== '') {
                $lines[] = '- ' . RecordedExecution::hintLine(depth: $depth, key: $change['key'], value: $change['before']);
            }
            if ($change['now'] !== '') {
                $lines[] = '+ ' . RecordedExecution::hintLine(depth: $depth, key: $change['key'], value: $change['now']);
            }
        }
        if ($details !== []) {
            $lines[] = '  ' . RecordedExecution::hintLine(depth: $depth, key: count($details) . ' detail hint' . (count($details) === 1 ? '' : 's') . ' changed', value: implode(', ', $details) . '; the json presentation shows ' . (count($details) === 1 ? 'its values' : 'their values'));
        }

        return $lines;
    }

    /**
     * @param list<MessageFlowGraph> $drawn
     */
    private static function flowchartLegend(array $drawn, ?FlowScenarioChange $marked): string
    {
        $nodes = array_merge(...array_map(fn (MessageFlowGraph $graph): array => $graph->nodes(), $drawn));
        $edges = array_merge(...array_map(fn (MessageFlowGraph $graph): array => $graph->edges(), $drawn));
        $drawnNodes = array_flip(array_map(fn (FlowNode $node): string => $node->id, $nodes));
        $drawnEdges = array_flip(array_map(fn (FlowEdge $edge): string => $edge->identity(), $edges));
        $isDrawn = fn (array $changedNodes, array $changedEdges): bool => array_filter($changedNodes, fn (FlowNode $node): bool => isset($drawnNodes[$node->id])) !== []
            || array_filter($changedEdges, fn (FlowEdge $edge): bool => isset($drawnEdges[$edge->identity()])) !== [];
        $hasArrow = fn (FlowEdgeKind $kind): bool => array_filter($edges, fn (FlowEdge $edge): bool => $edge->kind === $kind) !== [];
        $registeredByEcotone = array_filter($nodes, fn (FlowNode $node): bool => ($node->attributes['framework'] ?? false) === true);
        $groups = array_filter([
            $marked === null ? '' : implode(', ', array_filter([
                $isDrawn($marked->wiring->addedNodes, $marked->wiring->addedEdges) ? 'green = added' : null,
                $isDrawn($marked->wiring->removedNodes, $marked->wiring->removedEdges) ? 'red dashed = removed' : null,
                $isDrawn(array_map(fn (FlowNodeChange $change): FlowNode => $change->after, $marked->wiring->changedNodes), array_map(fn (FlowEdgeChange $change): FlowEdge => $change->after, $marked->wiring->changedEdges)) ? 'yellow = changed' : null,
            ])),
            implode(', ', array_filter([
                $registeredByEcotone !== [] ? 'dotted outline = registered by Ecotone' : null,
                count($registeredByEcotone) < count($nodes) ? 'solid outline = declared by the application' : null,
            ])),
            implode(', ', array_filter([
                $hasArrow(FlowEdgeKind::OUTPUT) ? '`then sends to` = the handler\'s result goes to that channel' : null,
                $hasArrow(FlowEdgeKind::ROUTES_TO) ? '`routed to` = chosen at runtime by a bus or a routing slip' : null,
                $hasArrow(FlowEdgeKind::INPUT) ? 'an unlabelled arrow out of a channel = consumed by' : null,
            ])),
        ]);

        return $groups === [] ? '' : '_Legend: ' . implode('; ', $groups) . "._\n\n";
    }

    /**
     * @param list<string> $scenarios
     * @param list<string> $varyingNames
     */
    private static function headingOf(array $scenarios, array $varyingNames): string
    {
        if (count($scenarios) === 1) {
            return '### ' . self::code($scenarios[0]) . "\n\n";
        }
        $names = array_map(fn (string $scenario): string => self::code($scenario), $scenarios);
        $shownFor = $varyingNames === [] ? '' : " Shown for `{$varyingNames[0]}`; the same holds with "
            . implode(' or ', array_map(fn (string $name): string => "`{$name}`", array_slice($varyingNames, 1))) . ' in its place.';

        return '### ' . count($scenarios) . " scenarios with the same change\n\n" . (
            count($scenarios) <= self::SCENARIOS_PER_LINE
                ? 'Applies to: ' . implode(', ', $names) . '.' . $shownFor . "\n\n"
                : "<details><summary>Applies to</summary>\n\n" . implode("\n", array_map(fn (string $name): string => '- ' . $name, $names)) . "\n\n</details>\n\n" . ($shownFor === '' ? '' : trim($shownFor) . "\n\n")
        );
    }

    private static function beforeAfterPair(FlowScenarioChange $change): string
    {
        $markdown = self::wiringHeading($change);
        $drawn = [];
        foreach ($change->wiringParts() as $position => $part) {
            $drawn[] = $change->wiringPartBefore($position);
            $drawn[] = $change->wiringPartAfter($position);
            $markdown .= self::partHeading($change, $position)
                . "Before:\n\n" . self::flowchart(self::readable(change: $change, part: $change->wiringPartBefore($position), whole: $change->before->wiring)->toMermaid())
                . "After:\n\n" . self::flowchart(self::readable(change: $change, part: $change->wiringPartAfter($position), whole: $change->after->wiring)->toMermaid())
                . self::leftOutLine($part['unchangedNeighboursLeftOut']);
        }
        $markdown .= self::flowchartLegend($drawn, null);
        if (! $change->hasTraceChanges()) {
            return $markdown . "#### Traced spans\n\nUnchanged.\n";
        }

        return $markdown . "#### Traced spans\n\n"
            . "Before:\n\n" . self::stepList(change: $change, skippedChange: FlowScenarioChange::ADDED, wiring: $change->before->wiring)
            . "After:\n\n" . self::stepList(change: $change, skippedChange: FlowScenarioChange::REMOVED, wiring: $change->after->wiring);
    }

    private static function markedDiagram(FlowScenarioChange $change): string
    {
        $markdown = self::wiringHeading($change);
        $drawn = [];
        foreach ($change->wiringParts() as $position => $part) {
            $drawn[] = $change->wiringPartOfBothSides($position);
            $markdown .= self::partHeading($change, $position)
                . self::flowchart(self::readable(change: $change, part: $change->wiringPartOfBothSides($position), whole: $change->wiring->combinedGraph(), entryPointsOf: $change->after->wiring)->toMermaidWithChangesOf($change->wiring))
                . self::leftOutLine($part['unchangedNeighboursLeftOut']);
        }
        $markdown .= self::flowchartLegend($drawn, $change);
        if (! $change->hasTraceChanges()) {
            return $markdown . "#### Traced spans\n\nUnchanged.\n";
        }
        $lines = [];
        foreach ($change->scopedTrace() as $entry) {
            $lines[] = match ($entry['change']) {
                FlowScenarioChange::LEFT_OUT => "@@ {$entry['steps']} steps unchanged @@",
                FlowScenarioChange::ADDED => '+' . self::stepLine($entry['step'], $change->after->wiring),
                FlowScenarioChange::REMOVED => '-' . self::stepLine($entry['step'], $change->before->wiring),
                default => ' ' . self::stepLine($entry['step'], $change->after->wiring),
            };
        }

        return $markdown . "#### Traced spans\n\n```diff\n" . implode("\n", self::capped($lines)) . "\n```\n";
    }

    private static function messagePath(FlowScenarioChange $change): string
    {
        $before = $change->messagePath(FlowScenarioChange::ADDED);
        $after = $change->messagePath(FlowScenarioChange::REMOVED);
        if ($before === [] && $after === []) {
            $before = self::arrowsAsPath(arrows: [...$change->wiring->removedEdges, ...array_map(fn (FlowEdgeChange $changed): FlowEdge => $changed->before, $change->wiring->changedEdges)], mark: '- ', change: $change);
            $after = self::arrowsAsPath(arrows: [...$change->wiring->addedEdges, ...array_map(fn (FlowEdgeChange $changed): FlowEdge => $changed->after, $change->wiring->changedEdges)], mark: '+ ', change: $change);
        }
        $edges = self::flowEdgesOf(before: $before, after: $after);
        if ($edges === []) {
            return "#### The message's path\n\nNothing to follow: neither the traced spans nor an arrow changed.\n";
        }
        $ids = [];
        foreach ($edges as $edge) {
            $ids[$edge['from']] ??= 'p' . count($ids);
            $ids[$edge['to']] ??= 'p' . count($ids);
        }
        $lines = ['flowchart LR'];
        foreach ($ids as $place => $id) {
            $touching = array_values(array_unique(array_map(fn (array $edge): string => $edge['change'], array_filter($edges, fn (array $edge): bool => $edge['from'] === $place || $edge['to'] === $place))));
            $mark = match ($touching) {
                ['new'] => ':::new',
                ['gone'] => ':::gone',
                default => '',
            };
            $label = self::flowchartText($place);
            $lines[] = '  ' . $id . (str_starts_with($place, 'channel ') ? '[("' . $label . '")]' : '["' . $label . '"]') . $mark;
        }
        $styles = [];
        foreach (array_values($edges) as $position => $edge) {
            $prefix = match ($edge['change']) {
                'new' => '+ ',
                'gone' => '- ',
                default => '',
            };
            $lines[] = '  ' . $ids[$edge['from']] . ($edge['derived'] ? ' -.->' : ' -->') . '|"' . self::flowchartText($prefix . $edge['label']) . '"| ' . $ids[$edge['to']];
            $stroke = match ($edge['change']) {
                'new' => '#2ea043',
                'gone' => '#f85149',
                default => null,
            };
            if ($stroke !== null) {
                $styles[] = "  linkStyle {$position} stroke:{$stroke},stroke-width:2.5px";
            }
        }
        $lines[] = '  classDef new fill:#2ea04333,stroke:#2ea043,stroke-width:2px';
        $lines[] = '  classDef gone fill:#f8514933,stroke:#f85149,stroke-width:2px';

        return "#### The message's path\n\nGreen marks what happens only now and red what happened only before: a green or red arrow is such a hand-over, a green or red box a step only such hand-overs reach; a plain arrow or box is there before and now, and a dashed arrow is taken from the wiring because no span is recorded.\n\n```mermaid\n" . implode("\n", [...$lines, ...$styles]) . "\n```\n\n";
    }

    /**
     * @param list<array{from: string, to: string, label: string, derived: bool}> $before
     * @param list<array{from: string, to: string, label: string, derived: bool}> $after
     * @return array<string, array{from: string, to: string, label: string, derived: bool, change: string}>
     */
    private static function flowEdgesOf(array $before, array $after): array
    {
        $distinct = function (array $path): array {
            $edges = [];
            foreach ($path as $arrow) {
                $label = (string) preg_replace('/ \((build|wiring), no span\)$/', '', (string) preg_replace('/^[+-] /', '', $arrow['label']));
                $edges[$arrow['from'] . "\0" . $arrow['to'] . "\0" . $label] ??= ['from' => $arrow['from'], 'to' => $arrow['to'], 'label' => $label, 'derived' => $arrow['derived']];
            }

            return $edges;
        };
        $then = $distinct($before);
        $now = $distinct($after);
        $edges = [];
        foreach ($now as $key => $edge) {
            $edges[$key] = [...$edge, 'change' => isset($then[$key]) ? 'kept' : 'new'];
        }
        foreach (array_diff_key($then, $now) as $key => $edge) {
            $edges[$key] = [...$edge, 'change' => 'gone'];
        }

        return $edges;
    }

    private static function flowchartText(string $text): string
    {
        return str_replace(['#', '"', '|', '`', '[', ']', '<', '>', "\n"], ['#35;', '#quot;', '#124;', '#96;', '#91;', '#93;', '#lt;', '#gt;', ' '], $text);
    }

    /**
     * @param list<FlowEdge> $arrows
     * @return list<array{from: string, to: string, label: string, derived: bool}>
     */
    private static function arrowsAsPath(array $arrows, string $mark, FlowScenarioChange $change): array
    {
        $combined = $change->wiring->combinedGraph();
        $offThePath = [];
        foreach ($change->registeredOffThePath() as $nodes) {
            foreach ($nodes as $node) {
                $offThePath[$node->id] = true;
            }
        }
        $path = [];
        foreach ($arrows as $arrow) {
            if (! isset($offThePath[$arrow->from]) && ! isset($offThePath[$arrow->to])) {
                $path[] = ['from' => FlowWording::ofNode($combined->node($arrow->from), $combined), 'to' => FlowWording::ofNode($combined->node($arrow->to), $combined), 'label' => $mark . FlowWording::ofArrow($arrow->kind), 'derived' => true];
            }
        }

        return $path;
    }

    private static function readable(FlowScenarioChange $change, MessageFlowGraph $part, MessageFlowGraph $whole, ?MessageFlowGraph $entryPointsOf = null): MessageFlowGraph
    {
        $partReportChannels = $change->partReportChannels();
        $nodes = array_map(
            fn (FlowNode $node): FlowNode => new FlowNode(
                id: $node->id,
                kind: $node->kind,
                label: $node->kind === FlowNodeKind::CHANNEL && isset($partReportChannels[$node->label])
                    ? $node->label . ' (entry point: part reports are sent here by ' . FlowWording::withShortClassNames($partReportChannels[$node->label]) . ')'
                    : FlowWording::ofNode($node, $entryPointsOf !== null && $entryPointsOf->hasNode($node->id) ? $entryPointsOf : $whole),
                attributes: $node->attributes,
            ),
            $part->nodes(),
        );
        usort($nodes, fn (FlowNode $first, FlowNode $second): int => strcmp($first->id, $second->id));

        return MessageFlowGraph::create($nodes, $part->edges());
    }

    private static function wiringHeading(FlowScenarioChange $change): string
    {
        return "#### Wiring\n\n" . match (true) {
            $change->state !== FlowScenarioChange::CHANGED => "The scenario was {$change->state}; its whole wiring is in its snapshot file.\n\n",
            $change->wiringParts() === [] => ($change->wiring->hasChanges() ? 'Nothing on the path changed.' : 'Unchanged.') . "\n\n",
            default => '',
        };
    }

    private static function partHeading(FlowScenarioChange $change, int $position): string
    {
        return count($change->wiringParts()) === 1 ? '' : 'Part ' . ($position + 1) . ' of ' . count($change->wiringParts()) . ":\n\n";
    }

    private static function flowchart(string $mermaid): string
    {
        return $mermaid === self::EMPTY_FLOWCHART
            ? "Nothing of this part exists on this side.\n\n"
            : "```mermaid\n" . str_replace(['|"output"|', '|"routes"|'], ['|"then sends to"|', '|"routed to"|'], $mermaid) . "```\n\n";
    }

    private static function leftOutLine(int $unchangedNeighboursLeftOut): string
    {
        return $unchangedNeighboursLeftOut === 0 ? '' : "_and {$unchangedNeighboursLeftOut} more unchanged neighbours_\n\n";
    }

    private static function stepList(FlowScenarioChange $change, string $skippedChange, MessageFlowGraph $wiring): string
    {
        $lines = [];
        foreach ($change->scopedTrace() as $entry) {
            if ($entry['change'] === $skippedChange) {
                continue;
            }
            $lines[] = match ($entry['change']) {
                FlowScenarioChange::LEFT_OUT => "… {$entry['steps']} steps unchanged",
                FlowScenarioChange::ADDED => self::stepLine($entry['step'], $wiring) . '   ← new',
                FlowScenarioChange::REMOVED => self::stepLine($entry['step'], $wiring) . '   ← gone',
                default => self::stepLine($entry['step'], $wiring),
            };
        }

        return "```text\n" . implode("\n", self::capped($lines)) . "\n```\n\n";
    }

    /**
     * @param array<string, mixed> $step
     */
    private static function stepLine(array $step, MessageFlowGraph $wiring): string
    {
        return str_repeat('  ', (int) $step['depth']) . FlowWording::ofStep($step, $wiring)
            . (isset($step['destination']) && ! str_ends_with(FlowWording::ofStep($step, $wiring), ' ' . $step['destination']) ? ' → ' . $step['destination'] : '')
            . ($step['status'] === 'Ok' ? '' : " ({$step['status']})")
            . (isset($step[FlowScenarioChange::PART_REPORT]) ? ' (part report by ' . FlowWording::withShortClassNames($step[FlowScenarioChange::PART_REPORT]['sender']) . ' to ' . $step[FlowScenarioChange::PART_REPORT]['channel'] . ')' : '')
            . (isset($step['derivedFromWiring']) ? self::DERIVED_STEP : '');
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function capped(array $lines): array
    {
        return count($lines) <= self::STEPS_PER_LIST ? $lines : [...array_slice($lines, 0, self::STEPS_PER_LIST), '… and ' . (count($lines) - self::STEPS_PER_LIST) . ' more lines'];
    }

}
