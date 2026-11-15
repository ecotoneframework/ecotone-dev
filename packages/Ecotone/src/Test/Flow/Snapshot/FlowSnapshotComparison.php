<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

use Ecotone\Api\Messaging\Flow\FlowNodeKind;

/**
 * licence Apache-2.0
 */
final class FlowSnapshotComparison
{
    private const NAMES_TRIED = 6;

    /**
     * @param list<array{scenarios: list<string>, varyingNames: list<string>, change: FlowScenarioChange}> $groups
     * @param array<string, list<array<string, mixed>>> $buildChangesByScenario scenario => its build changes beyond what every scenario shares
     * @param list<string> $projectionNames every projection of the compared scenarios
     */
    private function __construct(
        public readonly int $scenariosCompared,
        public readonly array $groups,
        public readonly ?FlowScenarioChange $everyScenario,
        public readonly array $buildChangesByScenario,
        public readonly array $projectionNames,
    ) {
    }

    /**
     * @param array<string, FlowSnapshot> $before file name => snapshot
     * @param array<string, FlowSnapshot> $after file name => snapshot
     */
    public static function between(array $before, array $after, ?FlowSnapshot $sharedBefore = null, ?FlowSnapshot $sharedAfter = null): self
    {
        $compared = [];
        foreach (array_keys($before + $after) as $fileName) {
            $compared[] = FlowScenarioChange::between($before[$fileName] ?? null, $after[$fileName] ?? null);
        }
        $everyScenario = $sharedBefore !== null && $sharedAfter !== null ? FlowScenarioChange::between($sharedBefore, $sharedAfter) : null;
        $sharedByEveryScenario = $everyScenario?->buildChangeIdentities() ?? [];
        $changes = [];
        $buildChangesByScenario = [];
        $projectionNames = [];
        foreach ([...$before, ...$after] as $snapshot) {
            foreach ($snapshot->wiring->nodes() as $node) {
                if ($node->kind === FlowNodeKind::PROJECTION) {
                    $projectionNames[$node->label] = true;
                }
            }
        }
        foreach ($compared as $change) {
            $change = $change->withoutBuildChangesOf(array_intersect_assoc($change->buildChangeIdentities(), $sharedByEveryScenario));
            if ($change->hasChanges()) {
                $changes[$change->scenario] = $change;
                if ($change->build !== []) {
                    $buildChangesByScenario[$change->scenario] = $change->build;
                }
            }
        }
        ksort($changes, SORT_STRING);
        $groups = [];
        foreach ($changes as $change) {
            $groups[$change->identity()] ??= ['scenarios' => [], 'varyingNames' => [], 'change' => $change];
            $groups[$change->identity()]['scenarios'][] = $change->scenario;
        }
        $merged = [];
        foreach ($groups as $group) {
            foreach ($merged as $position => $earlier) {
                $ownName = $otherName = null;
                $moreOften = fn (array $names, array $thanIn): array => array_keys(array_filter($names, fn (int $count, string $name): bool => $count > ($thanIn[$name] ?? 0), ARRAY_FILTER_USE_BOTH));
                $byLength = fn (string $first, string $second): int => [strlen($first), $first] <=> [strlen($second), $second];
                $ownNames = $earlier['varyingNames'] === [] ? $moreOften($earlier['change']->namesIn(), $group['change']->namesIn()) : [$earlier['varyingNames'][0]];
                $otherNames = $moreOften($group['change']->namesIn(), $earlier['change']->namesIn());
                usort($ownNames, $byLength);
                usort($otherNames, $byLength);
                foreach (array_slice($ownNames, 0, self::NAMES_TRIED) as $ownCandidate) {
                    foreach (array_slice($otherNames, 0, self::NAMES_TRIED) as $otherCandidate) {
                        if ($earlier['change']->identity($ownCandidate, $otherCandidate) === $group['change']->identity($otherCandidate, $ownCandidate)) {
                            [$ownName, $otherName] = [$ownCandidate, $otherCandidate];
                            break 2;
                        }
                    }
                }
                if ($ownName === null) {
                    continue;
                }
                $merged[$position]['scenarios'] = [...$earlier['scenarios'], ...$group['scenarios']];
                $merged[$position]['varyingNames'] = [...($earlier['varyingNames'] === [] ? [$ownName] : $earlier['varyingNames']), $otherName];
                continue 2;
            }
            $merged[] = $group;
        }
        $groups = $merged;

        return new self(count($before + $after), array_values($groups), $sharedByEveryScenario === [] ? null : $everyScenario, $buildChangesByScenario, array_keys($projectionNames));
    }

    /**
     * @return array{scenariosCompared: int, everyScenario: array{build: list<array<string, mixed>>}, groups: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'scenariosCompared' => $this->scenariosCompared,
            'everyScenario' => ['build' => $this->everyScenario?->buildByModule() ?? []],
            'groups' => array_map(fn (array $group): array => ['scenarios' => $group['scenarios'], 'varyingNames' => $group['varyingNames'], ...$group['change']->toArray()], $this->groups),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
