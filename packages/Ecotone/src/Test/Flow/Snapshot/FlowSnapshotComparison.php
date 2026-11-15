<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

/**
 * licence Apache-2.0
 */
final class FlowSnapshotComparison
{
    /**
     * @param list<array{scenarios: list<string>, varyingNames: list<string>, change: FlowScenarioChange}> $groups
     */
    private function __construct(public readonly int $scenariosCompared, public readonly array $groups, public readonly ?FlowScenarioChange $everyScenario)
    {
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
        foreach ($compared as $change) {
            $change = $change->withoutBuildChangesOf(array_intersect_assoc($change->buildChangeIdentities(), $sharedByEveryScenario));
            if ($change->hasChanges()) {
                $changes[$change->scenario] = $change;
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
                $ownNames = array_values(array_diff($earlier['change']->namesIn(), $group['change']->namesIn()));
                $otherNames = array_values(array_diff($group['change']->namesIn(), $earlier['change']->namesIn()));
                usort($ownNames, fn (string $first, string $second): int => [strlen($first), $first] <=> [strlen($second), $second]);
                usort($otherNames, fn (string $first, string $second): int => [strlen($first), $first] <=> [strlen($second), $second]);
                $ownName = $earlier['varyingNames'][0] ?? $ownNames[0] ?? null;
                if ($ownName === null || $otherNames === [] || $earlier['change']->identity($ownName) !== $group['change']->identity($otherNames[0])) {
                    continue;
                }
                $merged[$position]['scenarios'] = [...$earlier['scenarios'], ...$group['scenarios']];
                $merged[$position]['varyingNames'] = [...($earlier['varyingNames'] === [] ? [$ownName] : $earlier['varyingNames']), $otherNames[0]];
                continue 2;
            }
            $merged[] = $group;
        }
        $groups = $merged;

        return new self(count($before + $after), array_values($groups), $sharedByEveryScenario === [] ? null : $everyScenario);
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
