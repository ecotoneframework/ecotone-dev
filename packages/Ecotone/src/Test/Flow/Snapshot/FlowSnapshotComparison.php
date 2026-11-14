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
    private function __construct(public readonly int $scenariosCompared, public readonly array $groups)
    {
    }

    /**
     * @param array<string, FlowSnapshot> $before file name => snapshot
     * @param array<string, FlowSnapshot> $after file name => snapshot
     */
    public static function between(array $before, array $after): self
    {
        $changes = [];
        foreach (array_keys($before + $after) as $fileName) {
            $change = FlowScenarioChange::between($before[$fileName] ?? null, $after[$fileName] ?? null);
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

        return new self(count($before + $after), array_values($groups));
    }

    /**
     * @return array{scenariosCompared: int, groups: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'scenariosCompared' => $this->scenariosCompared,
            'groups' => array_map(fn (array $group): array => ['scenarios' => $group['scenarios'], 'varyingNames' => $group['varyingNames'], ...$group['change']->toArray()], $this->groups),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
