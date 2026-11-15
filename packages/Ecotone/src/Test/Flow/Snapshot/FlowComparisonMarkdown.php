<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowEdgeChange;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class FlowComparisonMarkdown
{
    public const BEFORE_AFTER_PAIR = 'A';
    public const MARKED_DIAGRAM = 'B';
    public const MESSAGE_PATH = 'C';
    public const BUILD_VIEW = 'BUILD';
    private const BUILD_LINES = 150;
    private const BUILD_SIGNS = [FlowScenarioChange::ADDED => '+', FlowScenarioChange::REMOVED => '-', FlowScenarioChange::CHANGED => '~'];
    private const STEPS_PER_LIST = 40;
    private const SCENARIOS_PER_LINE = 6;
    private const EMPTY_FLOWCHART = "flowchart LR\n";
    private const OUTLINES = 'dotted outline = registered by Ecotone, solid outline = declared by the application; `then sends to` = the handler\'s result goes to that channel, `routed to` = chosen at runtime by a bus or a routing slip, an unlabelled arrow out of a channel = consumed by';
    private const DERIVED_STEP = ' (from the wiring: no span is recorded)';

    public static function of(FlowSnapshotComparison $comparison, string $presentation): string
    {
        if (! in_array($presentation, [self::BEFORE_AFTER_PAIR, self::MARKED_DIAGRAM, self::MESSAGE_PATH, self::BUILD_VIEW], true)) {
            throw InvalidArgumentException::create("ecotone:internal-flow:compare was asked for presentation `{$presentation}`, which it cannot produce. Pass --presentation=A for a before/after pair of diagrams and step lists, --presentation=B for one diagram with the changes marked and one step list, --presentation=C for the message's path as a sequence diagram with what changed in sentences, --presentation=BUILD for the build, the message's path and the marked wiring collapsed, or --presentation=json for the raw comparison.");
        }
        $title = "## Message flow: what changed\n\n";
        if ($comparison->groups === [] && $comparison->everyScenario === null) {
            return $title . "None of {$comparison->scenariosCompared} scenarios changed its wiring, its build or its traced steps.\n";
        }
        $changed = array_sum(array_map(fn (array $group): int => count($group['scenarios']), $comparison->groups));
        $sections = array_map(
            fn (array $group): string => self::headingOf($group['scenarios'], $group['varyingNames'])
                . implode("\n\n", $group['change']->summary()) . "\n\n"
                . self::buildSection($group['change']) . "\n"
                . match ($presentation) {
                    self::BEFORE_AFTER_PAIR => self::beforeAfterPair($group['change']),
                    self::MARKED_DIAGRAM => self::markedDiagram($group['change']),
                    self::MESSAGE_PATH => self::messagePath($group['change']),
                    default => self::messagePath($group['change']) . "\n<details><summary>Marked wiring and traced steps</summary>\n\n" . self::markedDiagram($group['change']) . "\n</details>\n",
                }
            . self::offThePathLine($group['change']),
            $comparison->groups,
        );
        if ($comparison->everyScenario !== null) {
            array_unshift($sections, "### Every scenario\n\n" . self::buildSection($comparison->everyScenario));
        }

        return $title . "{$changed} of {$comparison->scenariosCompared} scenarios changed" . ($comparison->everyScenario === null ? '' : ' beyond what every scenario shares') . ".\n\n" . implode("\n", $sections);
    }

    private static function buildSection(FlowScenarioChange $change): string
    {
        $heading = "#### Build: what modules register now\n\n";
        if ($change->build === []) {
            return $heading . ($change->state === FlowScenarioChange::CHANGED ? 'Unchanged.' : "The scenario was {$change->state}; its whole build is in its snapshot file.") . "\n";
        }
        $lines = [];
        foreach ($change->buildByModule() as $module) {
            $lines[] = FlowWording::withShortClassNames($module['module']);
            foreach ($module['entries'] as $entry) {
                $now = FlowWording::ofRegistration($entry['after'] ?? $entry['before']);
                $lines[] = '  ' . self::BUILD_SIGNS[$entry['change']] . ' ' . $now;
                if ($entry['change'] === FlowScenarioChange::CHANGED) {
                    $was = FlowWording::ofRegistration($entry['before']);
                    if ($was !== $now) {
                        $lines[] = '      was: ' . $was;
                    }
                    if ($entry['before']['module'] !== $entry['after']['module']) {
                        $lines[] = '      was registered by ' . FlowWording::withShortClassNames($entry['before']['module']);
                    }
                }
                $chain = $entry['chain'] ?? [];
                $marksLayers = $entry['change'] === FlowScenarioChange::CHANGED;
                if ($marksLayers && array_filter($chain, fn (array $layer): bool => $layer['change'] !== FlowScenarioChange::UNCHANGED) === []) {
                    continue;
                }
                foreach ($chain as $layer) {
                    $lines[] = '      ' . match (true) {
                        ! $marksLayers => '',
                        $layer['change'] === FlowScenarioChange::ADDED => '+ ',
                        $layer['change'] === FlowScenarioChange::REMOVED => '- ',
                        default => '  ',
                    } . FlowWording::withShortClassNames($layer['layer']);
                }
            }
        }

        return $heading . "```text\n" . implode("\n", count($lines) <= self::BUILD_LINES ? $lines : [...array_slice($lines, 0, self::BUILD_LINES), '… and ' . (count($lines) - self::BUILD_LINES) . ' more lines; the raw comparison has all of them']) . "\n```\n";
    }

    /**
     * @param list<string> $scenarios
     * @param list<string> $varyingNames
     */
    private static function headingOf(array $scenarios, array $varyingNames): string
    {
        if (count($scenarios) === 1) {
            return "### `{$scenarios[0]}`\n\n";
        }
        $names = array_map(fn (string $scenario): string => "`{$scenario}`", $scenarios);
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
        foreach ($change->wiringParts() as $position => $part) {
            $markdown .= self::partHeading($change, $position)
                . "Before:\n\n" . self::flowchart(self::readable($change->wiringPartBefore($position), $change->before->wiring)->toMermaid())
                . "After:\n\n" . self::flowchart(self::readable($change->wiringPartAfter($position), $change->after->wiring)->toMermaid())
                . self::leftOutLine($part['unchangedNeighboursLeftOut']);
        }
        $markdown .= $change->wiringParts() === [] ? '' : '_Legend: ' . self::OUTLINES . "._\n\n";
        if (! $change->hasTraceChanges()) {
            return $markdown . "#### Traced steps\n\nUnchanged.\n";
        }

        return $markdown . "#### Traced steps\n\n"
            . "Before:\n\n" . self::stepList($change, FlowScenarioChange::ADDED, $change->before->wiring)
            . "After:\n\n" . self::stepList($change, FlowScenarioChange::REMOVED, $change->after->wiring);
    }

    private static function markedDiagram(FlowScenarioChange $change): string
    {
        $markdown = self::wiringHeading($change);
        foreach ($change->wiringParts() as $position => $part) {
            $markdown .= self::partHeading($change, $position)
                . self::flowchart(self::readable($change->wiringPartOfBothSides($position), $change->wiring->combinedGraph(), $change->after->wiring)->toMermaidWithChangesOf($change->wiring))
                . self::leftOutLine($part['unchangedNeighboursLeftOut']);
        }
        $markdown .= $change->wiringParts() === [] ? '' : '_Legend: green = added, red dashed = removed, yellow = changed; ' . self::OUTLINES . "._\n\n";
        if (! $change->hasTraceChanges()) {
            return $markdown . "#### Traced steps\n\nUnchanged.\n";
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

        return $markdown . "#### Traced steps\n\n```diff\n" . implode("\n", self::capped($lines)) . "\n```\n";
    }

    private static function messagePath(FlowScenarioChange $change): string
    {
        $before = $change->messagePath(FlowScenarioChange::ADDED);
        $after = $change->messagePath(FlowScenarioChange::REMOVED);
        if ($before === [] && $after === []) {
            $before = self::arrowsAsPath([...$change->wiring->removedEdges, ...array_map(fn (FlowEdgeChange $changed): FlowEdge => $changed->before, $change->wiring->changedEdges)], '- ', $change);
            $after = self::arrowsAsPath([...$change->wiring->addedEdges, ...array_map(fn (FlowEdgeChange $changed): FlowEdge => $changed->after, $change->wiring->changedEdges)], '+ ', $change);
        }
        $participants = [];
        $sides = [];
        foreach (['Before' => ['255, 235, 233', $before], 'After' => ['218, 251, 225', $after]] as $side => [$colour, $path]) {
            $arrows = [];
            foreach ($path as $position => $arrow) {
                $needed = count(array_diff_key([$arrow['from'] => true, $arrow['to'] => true], $participants));
                if (count($participants) + $needed > FlowScenarioChange::NODES_PER_PART) {
                    $arrows[] = '    Note over ' . $participants[$path[$position - 1]['to'] ?? array_key_first($participants)] . ': and ' . (count($path) - $position) . ' more steps';
                    break;
                }
                $participants[$arrow['from']] ??= 'p' . count($participants);
                $participants[$arrow['to']] ??= 'p' . count($participants);
                $arrows[] = "    {$participants[$arrow['from']]}" . ($arrow['derived'] ? '-->>' : '->>') . "{$participants[$arrow['to']]}: " . self::sequenceText($arrow['label']);
            }
            if ($arrows !== []) {
                $sides[] = [$side, $colour, $arrows];
            }
        }
        $lines = [];
        foreach ($sides as [$side, $colour, $arrows]) {
            $width = count($participants) === 1 ? 'p0' : 'p0,p' . (count($participants) - 1);
            $lines = [...$lines, "    rect rgb({$colour})", "    Note over {$width}: {$side}", ...$arrows, '    end'];
        }
        $declared = array_map(fn (string $place, string $id): string => "    participant {$id} as " . self::sequenceText($place), array_keys($participants), $participants);

        return "#### The message's path\n\n" . ($lines === [] ? "Nothing to follow: neither the traced steps nor an arrow changed.\n" : "```mermaid\nsequenceDiagram\n" . implode("\n", [...$declared, ...$lines]) . "\n```\n\n"
            . "_Legend: solid arrow = a traced step, dashed arrow = taken from the wiring because no span is recorded; `+` = new, `-` = gone._\n");
    }

    private static function offThePathLine(FlowScenarioChange $change): string
    {
        $offThePath = $change->registeredOffThePath();
        $lines = [];
        foreach (['added' => ['Also registered now, not on this path: ', $change->after->wiring], 'removed' => ['No longer registered, not on this path: ', $change->before->wiring]] as $side => [$opening, $wiring]) {
            $names = [];
            foreach ($offThePath[$side] as $node) {
                if (! in_array($node->kind->value, ['channel', 'interceptor'], true) || count($offThePath[$side]) === 1) {
                    $names[] = '`' . FlowWording::ofNode($node, $wiring) . '`';
                }
            }
            sort($names, SORT_STRING);
            if ($names !== []) {
                $lines[] = "\n" . $opening . implode(', ', array_slice($names, 0, 8)) . (count($names) > 8 ? ' and ' . (count($names) - 8) . ' more' : '') . ".\n";
            }
        }

        return implode('', $lines);
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

    private static function readable(MessageFlowGraph $part, MessageFlowGraph $whole, ?MessageFlowGraph $entryPointsOf = null): MessageFlowGraph
    {
        $nodes = array_map(
            fn (FlowNode $node): FlowNode => new FlowNode($node->id, $node->kind, FlowWording::ofNode($node, $entryPointsOf !== null && $entryPointsOf->hasNode($node->id) ? $entryPointsOf : $whole), $node->attributes),
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

    private static function sequenceText(string $text): string
    {
        return str_replace(['#', ';', "\n"], ['№', ',', ' '], $text);
    }
}
