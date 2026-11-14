<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

/**
 * licence Apache-2.0
 */
final class FlowSnapshotComparison
{
    /**
     * @param list<array{scenarios: list<string>, change: FlowScenarioChange}> $groups
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
            $groups[$change->identity()] ??= ['scenarios' => [], 'change' => $change];
            $groups[$change->identity()]['scenarios'][] = $change->scenario;
        }

        return new self(count($before + $after), array_values($groups));
    }

    /**
     * @return array{scenariosCompared: int, groups: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'scenariosCompared' => $this->scenariosCompared,
            'groups' => array_map(fn (array $group): array => ['scenarios' => $group['scenarios'], ...$group['change']->toArray()], $this->groups),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
