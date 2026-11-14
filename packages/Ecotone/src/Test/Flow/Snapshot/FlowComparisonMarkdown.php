<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowEdgeChange;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeChange;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class FlowComparisonMarkdown
{
    public const BEFORE_AFTER_PAIR = 'A';
    public const MARKED_DIAGRAM = 'B';
    public const MESSAGE_PATH = 'C';
    private const STEPS_PER_LIST = 40;
    private const NAMES_PER_SENTENCE = 4;
    private const EMPTY_FLOWCHART = "flowchart LR\n";

    public static function of(FlowSnapshotComparison $comparison, string $presentation): string
    {
        if (! in_array($presentation, [self::BEFORE_AFTER_PAIR, self::MARKED_DIAGRAM, self::MESSAGE_PATH], true)) {
            throw InvalidArgumentException::create("ecotone:internal-flow:compare was asked for presentation `{$presentation}`, which it cannot produce. Pass --presentation=A for a before/after pair of diagrams and step lists, --presentation=B for one diagram with the changes marked and one step list, --presentation=C for the message's path as a sequence diagram with what changed in sentences, or --presentation=json for the raw comparison.");
        }
        $title = "## Message flow: what changed\n\n";
        if ($comparison->groups === []) {
            return $title . "None of {$comparison->scenariosCompared} scenarios changed its wiring or its traced steps.\n";
        }
        $changed = array_sum(array_map(fn (array $group): int => count($group['scenarios']), $comparison->groups));
        $sections = array_map(
            fn (array $group): string => self::headingOf($group['scenarios']) . match ($presentation) {
                self::BEFORE_AFTER_PAIR => self::beforeAfterPair($group['change']),
                self::MARKED_DIAGRAM => self::markedDiagram($group['change']),
                default => self::messagePath($group['change']),
            },
            $comparison->groups,
        );

        return $title . "{$changed} of {$comparison->scenariosCompared} scenarios changed.\n\n" . implode("\n", $sections);
    }

    /**
     * @param list<string> $scenarios
     */
    private static function headingOf(array $scenarios): string
    {
        if (count($scenarios) === 1) {
            return "### `{$scenarios[0]}`\n\n";
        }

        return '### ' . count($scenarios) . " scenarios with the same change\n\n<details><summary>Scenarios</summary>\n\n"
            . implode("\n", array_map(fn (string $scenario): string => "- `{$scenario}`", $scenarios))
            . "\n\n</details>\n\n";
    }

    private static function beforeAfterPair(FlowScenarioChange $change): string
    {
        $markdown = self::wiringHeading($change);
        foreach ($change->wiringParts() as $position => $part) {
            $markdown .= self::partHeading($change, $position)
                . "Before:\n\n" . self::flowchart($change->wiringPartBefore($position)->toMermaid())
                . "After:\n\n" . self::flowchart($change->wiringPartAfter($position)->toMermaid())
                . self::leftOutLine($part['unchangedNeighboursLeftOut']);
        }
        if (! $change->hasTraceChanges()) {
            return $markdown . "#### Traced steps\n\nUnchanged.\n";
        }

        return $markdown . "#### Traced steps\n\n"
            . "Before:\n\n" . self::stepList($change, FlowScenarioChange::ADDED)
            . "After:\n\n" . self::stepList($change, FlowScenarioChange::REMOVED);
    }

    private static function markedDiagram(FlowScenarioChange $change): string
    {
        $markdown = self::wiringHeading($change);
        foreach ($change->wiringParts() as $position => $part) {
            $markdown .= self::partHeading($change, $position)
                . self::flowchart($change->wiringPartOfBothSides($position)->toMermaidWithChangesOf($change->wiring))
                . self::leftOutLine($part['unchangedNeighboursLeftOut']);
        }
        if (! $change->hasTraceChanges()) {
            return $markdown . "#### Traced steps\n\nUnchanged.\n";
        }
        $lines = [];
        foreach ($change->scopedTrace() as $entry) {
            $lines[] = match ($entry['change']) {
                FlowScenarioChange::LEFT_OUT => "@@ {$entry['steps']} steps unchanged @@",
                FlowScenarioChange::ADDED => '+' . self::stepLine($entry['step']),
                FlowScenarioChange::REMOVED => '-' . self::stepLine($entry['step']),
                default => ' ' . self::stepLine($entry['step']),
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
        $lines = [];
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
                $arrows[] = "    {$participants[$arrow['from']]}->>{$participants[$arrow['to']]}: " . self::sequenceText($arrow['label']);
            }
            if ($arrows === []) {
                continue;
            }
            $firstArrow = $path[0];
            $lines = [...$lines, "    rect rgb({$colour})", "    Note over {$participants[$firstArrow['from']]}: {$side}", ...$arrows, '    end'];
        }
        $declared = array_map(fn (string $place, string $id): string => "    participant {$id} as " . self::sequenceText($place), array_keys($participants), $participants);

        return "#### The message's path\n\n"
            . ($lines === [] ? '' : "```mermaid\nsequenceDiagram\n" . implode("\n", [...$declared, ...$lines]) . "\n```\n\n")
            . implode("\n", self::sentencesOf($change)) . "\n";
    }

    /**
     * @param list<FlowEdge> $arrows
     * @return list<array{from: string, to: string, label: string}>
     */
    private static function arrowsAsPath(array $arrows, string $mark, FlowScenarioChange $change): array
    {
        $combined = $change->wiring->combinedGraph();

        return array_map(
            fn (FlowEdge $arrow): array => ['from' => self::nameOf($combined->node($arrow->from)), 'to' => self::nameOf($combined->node($arrow->to)), 'label' => $mark . $arrow->kind->value],
            $arrows,
        );
    }

    /**
     * @return list<string>
     */
    private static function sentencesOf(FlowScenarioChange $change): array
    {
        if ($change->state !== FlowScenarioChange::CHANGED) {
            return ['- The scenario was ' . $change->state . ': all ' . count($change->trace) . ' of its traced steps are ' . ($change->state === FlowScenarioChange::ADDED ? 'new' : 'gone') . '. Its whole wiring is in its snapshot file.'];
        }
        $wiring = array_filter([
            self::counted($change->wiring->addedNodes, 'node', 'added', self::nameOf(...)),
            self::counted($change->wiring->removedNodes, 'node', 'removed', self::nameOf(...)),
            self::counted($change->wiring->changedNodes, 'node', 'changed', fn (FlowNodeChange $changed): string => self::nameOf($changed->after)),
            self::counted($change->wiring->addedEdges, 'arrow', 'added', null),
            self::counted($change->wiring->removedEdges, 'arrow', 'removed', null),
            self::counted($change->wiring->changedEdges, 'arrow', 'changed', null),
        ]);
        $steps = ['added' => [], 'removed' => []];
        foreach ($change->trace as $entry) {
            if ($entry['change'] !== FlowScenarioChange::UNCHANGED) {
                $steps[$entry['change']][] = '`' . trim(self::stepLine(['depth' => 0] + $entry['step'])) . '`';
            }
        }
        $trace = array_filter([
            $steps['added'] === [] ? null : 'the message now also takes ' . self::listed($steps['added']),
            $steps['removed'] === [] ? null : 'the message no longer takes ' . self::listed($steps['removed']),
        ]);

        return [
            '- Wiring: ' . ($wiring === [] ? 'unchanged' : implode(', ', $wiring)) . '.',
            '- Traced steps: ' . ($trace === [] ? 'unchanged' : implode('; ', $trace)) . '.',
        ];
    }

    /**
     * @param list<mixed> $elements
     */
    private static function counted(array $elements, string $what, string $change, ?callable $nameOf): ?string
    {
        if ($elements === []) {
            return null;
        }
        $sentence = count($elements) . ' ' . $what . (count($elements) === 1 ? '' : 's') . ' ' . $change;

        return $nameOf === null ? $sentence : $sentence . ' (' . self::listed(array_map(fn (mixed $element): string => '`' . $nameOf($element) . '`', $elements)) . ')';
    }

    /**
     * @param list<string> $names
     */
    private static function listed(array $names): string
    {
        $shown = array_slice($names, 0, self::NAMES_PER_SENTENCE);

        return implode(', ', $shown) . (count($names) > count($shown) ? ' and ' . (count($names) - count($shown)) . ' more' : '');
    }

    private static function nameOf(FlowNode $node): string
    {
        return ucwords((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $node->kind->value)) . ' ' . $node->label;
    }

    private static function wiringHeading(FlowScenarioChange $change): string
    {
        return "#### Wiring\n\n" . match (true) {
            $change->state !== FlowScenarioChange::CHANGED => "The scenario was {$change->state}; its whole wiring is in its snapshot file.\n\n",
            $change->wiringParts() === [] => "Unchanged.\n\n",
            default => '',
        };
    }

    private static function partHeading(FlowScenarioChange $change, int $position): string
    {
        return count($change->wiringParts()) === 1 ? '' : 'Part ' . ($position + 1) . ' of ' . count($change->wiringParts()) . ":\n\n";
    }

    private static function flowchart(string $mermaid): string
    {
        return $mermaid === self::EMPTY_FLOWCHART ? "Nothing of this part exists on this side.\n\n" : "```mermaid\n{$mermaid}```\n\n";
    }

    private static function leftOutLine(int $unchangedNeighboursLeftOut): string
    {
        return $unchangedNeighboursLeftOut === 0 ? '' : "_and {$unchangedNeighboursLeftOut} more unchanged neighbours_\n\n";
    }

    private static function stepList(FlowScenarioChange $change, string $skippedChange): string
    {
        $lines = [];
        foreach ($change->scopedTrace() as $entry) {
            if ($entry['change'] === $skippedChange) {
                continue;
            }
            if ($entry['change'] === FlowScenarioChange::LEFT_OUT) {
                $lines[] = "… {$entry['steps']} steps unchanged";
                continue;
            }
            $lines[] = self::stepLine($entry['step']);
        }

        return "```text\n" . implode("\n", self::capped($lines)) . "\n```\n\n";
    }

    /**
     * @param array<string, mixed> $step
     */
    private static function stepLine(array $step): string
    {
        return str_repeat('  ', (int) $step['depth']) . $step['name']
            . (isset($step['destination']) ? ' → ' . $step['destination'] : '')
            . ($step['status'] === 'Ok' ? '' : " ({$step['status']})");
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
