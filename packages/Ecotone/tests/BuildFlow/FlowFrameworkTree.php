<?php

declare(strict_types=1);

namespace Test\Ecotone\BuildFlow;

/**
 * licence Apache-2.0
 */
final class FlowFrameworkTree
{
    private const EVERY_SCENARIO = "\0every scenario";
    private const SIGNS = [FlowScenarioChange::ADDED => '+', FlowScenarioChange::REMOVED => '-', FlowScenarioChange::CHANGED => '~'];
    private const ROUTING = '\RoutingSlipPrepender::transform';
    private const CHAINED_STEPS = '\ChainedMessageProcessor::process';
    private const CONSOLE_COMMAND_PARTS = ['ecotone.endpoint.', 'ecotone.channel.'];
    private const STANDARD_RESULT = ['routing slip: the result goes to the next channel on it', 'reply channel of the message'];
    private const RESULT = '/^(routing slip: |part completion |reply channel of the message$|the channel the router resolves$)/';
    private const CONSUMER = '/^(event driven|polling) consumer of /';
    private const INTERCEPTOR = '/^(around|before|after) interceptor /';
    private const THEN_TO = '/^(?:output channel |each split message to output channel )(.+)$/';
    private const NAMED_CHANNEL = '/^((?:event driven|polling) consumer of |output channel |each split message to output channel ).*$/';
    private const INVOCATION = '/^((?:[a-z][a-z ]*? )?)(\S+::\w+)\((.*)\)$/';

    /** @var list<string> */
    private array $counted = [];
    /** @var list<string> */
    private array $scopes = [];

    private function __construct(private readonly FlowSnapshotComparison $comparison)
    {
    }

    public static function of(FlowSnapshotComparison $comparison): string
    {
        return (new self($comparison))->markdown();
    }

    private function markdown(): string
    {
        $modules = [];
        foreach ($this->groups() as $group) {
            $modules[FlowWording::withShortClassNames($this->moduleOf($group['change']))][] = $group;
        }
        if ($modules === []) {
            return '';
        }
        ksort($modules, SORT_STRING);
        $moduleNodes = [];
        foreach ($modules as $module => $groups) {
            $moduleNodes[] = ['text' => $module, 'children' => $this->registrationsOf($module, $groups)];
        }
        $details = '';
        foreach (['Counted registrations' => $this->counted, 'Scenarios of each scope' => $this->scopes] as $summary => $lines) {
            $details .= $lines === [] ? '' : "\n<details><summary>{$summary}</summary>\n\n" . implode("\n", $lines) . "\n\n</details>\n";
        }

        return "### Framework: what changes\n\n```text\nMessagingSystem\n" . implode("\n", $this->lines($moduleNodes, '')) . "\n```\n" . $details;
    }

    /**
     * @return list<array{change: array<string, mixed>, names: list<string>, scenarios: list<string>, variants: list<array{change: array<string, mixed>, scenarios: list<string>}>}>
     */
    private function groups(): array
    {
        $items = [];
        foreach ($this->comparison->everyScenario?->build ?? [] as $change) {
            $items[] = [$change, self::EVERY_SCENARIO];
        }
        foreach ($this->comparison->buildChangesByScenario as $scenario => $changes) {
            foreach ($changes as $change) {
                $items[] = [$change, $scenario];
            }
        }
        $grouped = [];
        foreach ($items as [$change, $scenario]) {
            $grouped[$this->generalised($change)][] = [$change, $scenario];
        }
        $groups = [];
        foreach ($grouped as $key => $members) {
            $perScenario = array_count_values(array_map(fn (array $member): string => $member[1], $members));
            $byIdentity = [];
            foreach ($members as $member) {
                $byIdentity[max($perScenario) > 1 ? $key . $member[0]['name'] : $key][] = $member;
            }
            foreach ($byIdentity as $sameChange) {
                $groups[] = [
                    'change' => $sameChange[0][0],
                    'names' => array_values(array_unique(array_map(fn (array $member): string => $member[0]['name'], $sameChange))),
                    'scenarios' => array_values(array_unique(array_map(fn (array $member): string => $member[1], $sameChange))),
                    'variants' => [],
                ];
            }
        }
        $merged = [];
        foreach ($groups as $group) {
            $registration = $group['change']['kind'] === 'service' ? $this->moduleOf($group['change']) . "\0" . $group['change']['name'] : null;
            if ($registration === null) {
                $merged[] = $group;
                continue;
            }
            $merged[$registration] ??= [...$group, 'scenarios' => [], 'variants' => []];
            $merged[$registration]['variants'][] = ['change' => $group['change'], 'scenarios' => $group['scenarios']];
            $merged[$registration]['scenarios'] = array_values(array_unique([...$merged[$registration]['scenarios'], ...$group['scenarios']]));
        }

        return array_values($merged);
    }

    /**
     * @param array<string, mixed> $change
     */
    private function generalised(array $change): string
    {
        if ($change['kind'] !== 'handler') {
            return json_encode([$change['change'], $change['kind'], $change['name'], $change['before'] ?? null, $change['after'] ?? null], JSON_THROW_ON_ERROR);
        }
        $withoutNames = fn (?array $entry): ?array => $entry === null ? null : array_diff_key($entry, ['name' => true, 'inputChannel' => true, 'outputChannel' => true]);

        return json_encode([
            $change['change'],
            $withoutNames($change['before'] ?? null),
            $withoutNames($change['after'] ?? null),
            array_map(fn (array $layer): array => [$layer['change'], preg_replace(self::NAMED_CHANNEL, '$1', $layer['layer'])], $change['chain'] ?? []),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $change
     */
    private function moduleOf(array $change): string
    {
        return (string) ($change['after'] ?? $change['before'])['module'];
    }

    /**
     * @param list<array{change: array<string, mixed>, names: list<string>, scenarios: list<string>, variants: list<array{change: array<string, mixed>, scenarios: list<string>}>}> $groups
     * @return list<array{text: string, lines?: list<string>, marked?: bool, children?: list<array<string, mixed>>}>
     */
    private function registrationsOf(string $module, array $groups): array
    {
        $endpoints = [];
        $spelledOut = [];
        $folded = [];
        foreach ($groups as $group) {
            $change = $group['change'];
            $entry = $change['after'] ?? $change['before'];
            $sign = self::SIGNS[$change['change']];
            if ($this->belongsToAConsoleCommand($entry)) {
                continue;
            }
            if ($change['kind'] === 'handler') {
                $endpoints[] = $this->endpoint($group);
            } elseif ($group['variants'] !== [] && (count($group['variants']) > 1 || isset($entry['enterprise']) || $change['change'] === FlowScenarioChange::CHANGED)) {
                $spelledOut[] = $this->service($group);
            } elseif ($change['change'] === FlowScenarioChange::CHANGED) {
                $spelledOut[] = [
                    'text' => "~ {$change['kind']} " . FlowWording::withShortClassNames((string) $change['name']) . $this->scopeOf($group, $change['kind'] . ' ' . FlowWording::withShortClassNames((string) $change['name'])),
                    'marked' => true,
                    'lines' => $this->whatChangedIn($change),
                ];
            } else {
                $folded[$sign][$change['kind']][$this->withOneNameForWhatVaries((string) $change['name'])] = true;
            }
        }
        usort($endpoints, fn (array $first, array $second): int => strcmp(substr($first['text'], 2), substr($second['text'], 2)));
        usort($spelledOut, fn (array $first, array $second): int => strcmp(substr($first['text'], 2), substr($second['text'], 2)));
        $counts = [];
        foreach ($folded as $sign => $kinds) {
            ksort($kinds, SORT_STRING);
            $counted = [];
            foreach ($kinds as $kind => $names) {
                ksort($names, SORT_STRING);
                $counted[] = count($names) . ' ' . $kind . (count($names) === 1 ? '' : 's');
                $this->counted[] = "- {$module} {$sign} {$kind}: " . implode(', ', array_map(fn (string $name): string => '`' . FlowWording::withShortClassNames($name) . '`', array_keys($names)));
            }
            $counts[] = ['text' => $sign . ' ' . implode(', ', $counted), 'marked' => true];
        }

        return [...$endpoints, ...$spelledOut, ...$counts];
    }

    /**
     * @param array<string, scalar> $entry
     */
    private function belongsToAConsoleCommand(array $entry): bool
    {
        if (! in_array($entry['kind'], ['handler', 'channel'], true)) {
            return false;
        }
        foreach (self::CONSOLE_COMMAND_PARTS as $prefix) {
            if (str_starts_with((string) $entry['name'], $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function withOneNameForWhatVaries(string $name): string
    {
        foreach ($this->comparison->groups as $group) {
            foreach (array_slice($group['varyingNames'], 1) as $otherName) {
                $name = str_ireplace($otherName, $group['varyingNames'][0], $name);
            }
        }

        return $name;
    }

    /**
     * @param array{change: array<string, mixed>, names: list<string>, scenarios: list<string>, variants: list<array<string, mixed>>} $group
     * @return array{text: string, lines?: list<string>, marked?: bool, children?: list<array<string, mixed>>}
     */
    private function endpoint(array $group): array
    {
        $change = $group['change'];
        $entry = $change['after'] ?? $change['before'];
        $sign = self::SIGNS[$change['change']];
        $target = (string) ($entry['target'] ?? '');
        if (str_ends_with($target, self::ROUTING) && $change['change'] !== FlowScenarioChange::CHANGED) {
            $label = 'asynchronous routing of ' . ($entry['inputChannel'] ?? '');

            return ['text' => "{$sign} {$label}" . $this->scopeOf($group, $label), 'marked' => true, 'children' => isset($entry['outputChannel']) ? [['text' => 'then to ' . $entry['outputChannel']]] : []];
        }
        $name = (string) $entry['name'];
        $label = 'endpoint ' . match (true) {
            str_ends_with($target, self::CHAINED_STEPS) => 'on ' . ($entry['inputChannel'] ?? $name),
            $target !== '' && ($name === $target || str_contains($name, ' on ')) => FlowWording::withShortClassNames($target),
            default => FlowWording::withShortClassNames($name),
        };
        $chain = $change['chain'] ?? [];

        return [
            'text' => "{$sign} {$label}" . $this->scopeOf($group, $label),
            'marked' => true,
            'lines' => match (true) {
                $change['change'] === FlowScenarioChange::CHANGED => $this->whatChangedIn($change),
                str_starts_with($label, 'endpoint on ') => [],
                default => ['on ' . ($entry['inputChannel'] ?? 'no channel')],
            },
            'children' => $change['change'] === FlowScenarioChange::CHANGED ? $this->nested($this->changedLayers($chain)) : $this->nested($this->allLayers($chain)),
        ];
    }

    /**
     * @param array{change: array<string, mixed>, names: list<string>, scenarios: list<string>, variants: list<array{change: array<string, mixed>, scenarios: list<string>}>} $group
     * @return array{text: string, lines: list<string>, marked: bool}
     */
    private function service(array $group): array
    {
        $describe = function (array $entry): string {
            return match (true) {
                isset($entry['enterprise']) => 'Enterprise -> ' . FlowWording::withShortClassNames((string) $entry['enterprise']) . ', open core -> ' . FlowWording::withShortClassNames((string) $entry['openCore']),
                default => FlowWording::withShortClassNames((string) ($entry['aliasOf'] ?? $entry['class'] ?? $entry['name'])),
            };
        };
        $signs = array_values(array_unique(array_map(fn (array $variant): string => self::SIGNS[$variant['change']['change']], $group['variants'])));
        $label = 'service ' . FlowWording::withShortClassNames((string) $group['change']['name']);
        $variants = $group['variants'];
        usort($variants, fn (array $first, array $second): int => [in_array(self::EVERY_SCENARIO, $first['scenarios'], true)] <=> [in_array(self::EVERY_SCENARIO, $second['scenarios'], true)]);
        $lines = [];
        foreach ($variants as $variant) {
            $change = $variant['change'];
            $now = isset($change['after']) ? $describe($change['after']) : 'removed';
            $was = isset($change['before'], $change['after']) ? $describe($change['before']) : null;
            $scope = count($variants) === 1 ? '' : (in_array(self::EVERY_SCENARIO, $variant['scenarios'], true) ? ' (the other scenarios)' : ' (' . count($variant['scenarios']) . ' scenario' . (count($variant['scenarios']) === 1 ? '' : 's') . ')');
            if ($was === null && ! isset($change['after']['enterprise']) && count($variants) === 1) {
                continue;
            }
            $lines[] = ($was !== null && $was !== $now && ! isset($change['after']['enterprise']) ? $was . ' -> ' : '') . $now . $scope;
        }

        return ['text' => (count($signs) === 1 ? $signs[0] : '~') . ' ' . $label . $this->scopeOf($group, $label), 'marked' => true, 'lines' => $lines];
    }

    /**
     * @param array<string, mixed> $change
     * @return list<string>
     */
    private function whatChangedIn(array $change): array
    {
        [$before, $after] = [$change['before'], $change['after']];
        $lines = [];
        foreach (['builder' => '', 'inputChannel' => 'on ', 'outputChannel' => 'then to ', 'target' => 'invokes ', 'pollable' => 'pollable ', 'class' => '', 'requestChannel' => 'sends to ', 'position' => ''] as $field => $opening) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $lines[] = $opening . FlowWording::withShortClassNames(json_encode($before[$field] ?? 'nothing', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . ' -> ' . FlowWording::withShortClassNames(json_encode($after[$field] ?? 'nothing', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
        }
        if ($before['module'] !== $after['module']) {
            $lines[] = 'was registered by ' . FlowWording::withShortClassNames((string) $before['module']);
        }

        return array_map(fn (string $line): string => str_replace(['"', '\\\\'], ['', '\\'], $line), $lines);
    }

    /**
     * @param array{change: array<string, mixed>, names: list<string>, scenarios: list<string>} $group
     */
    private function scopeOf(array $group, string $label): string
    {
        if ($this->comparison->scenariosCompared <= 1) {
            return '';
        }
        if (in_array(self::EVERY_SCENARIO, $group['scenarios'], true)) {
            return ' (every scenario)';
        }
        if (count($group['names']) > 1 && $this->comparison->projectionNames !== []) {
            $projections = $this->comparison->projectionNames;
            usort($projections, fn (string $first, string $second): int => strlen($second) <=> strlen($first));
            $templates = [];
            $named = [];
            foreach ($group['names'] as $name) {
                foreach ($projections as $projection) {
                    $template = preg_replace('/(?<![A-Za-z0-9_])' . preg_quote($projection, '/') . '(?![A-Za-z0-9_])/', '{projection}', $name);
                    if ($template !== $name) {
                        $templates[$template] = true;
                        $named[$projection] = true;
                        continue 2;
                    }
                }
                $templates[$name] = true;
            }
            if (count($templates) === 1 && count($named) === count($group['names'])) {
                return count($named) === count($projections) ? ' (every projection)' : ' (' . count($named) . ' projections)';
            }
        }
        if (count($group['scenarios']) === $this->comparison->scenariosCompared) {
            return ' (every scenario)';
        }
        $scenarios = $group['scenarios'];
        sort($scenarios, SORT_STRING);
        $otherNames = array_map(fn (string $name): string => FlowWording::withShortClassNames(str_contains($name, ' on ') ? substr($name, strpos($name, ' on ') + 1) : $name), array_slice($group['names'], 1));
        $this->scopes[] = "- {$label}" . ($otherNames === [] ? '' : ' (elsewhere ' . implode(', ', $otherNames) . ')') . ': ' . implode(', ', array_map(fn (string $scenario): string => "`{$scenario}`", $scenarios));

        return ' (' . count($scenarios) . ' scenario' . (count($scenarios) === 1 ? '' : 's') . ')';
    }

    /**
     * @param list<array{change: string, layer: string}> $chain
     * @return list<array{text: string, lines?: list<string>}>
     */
    private function allLayers(array $chain): array
    {
        $layers = [];
        $interceptors = 0;
        foreach ($chain as $layer) {
            $text = $layer['layer'];
            if (preg_match(self::INTERCEPTOR, $text) === 1) {
                $interceptors++;
                continue;
            }
            if ($interceptors > 0) {
                $layers[] = ['text' => "interceptors ({$interceptors})"];
                $interceptors = 0;
            }
            if (preg_match(self::RESULT, $text) === 1) {
                continue;
            }
            $layers[] = ['text' => $this->readable($text)];
        }

        return $layers;
    }

    /**
     * @param list<array{change: string, layer: string}> $chain
     * @return list<array{text: string, lines?: list<string>, children?: list<array<string, mixed>>}>
     */
    private function changedLayers(array $chain): array
    {
        $chain = $this->withTheStandardResultAsOneLayer($chain);
        $layers = [];
        $lastChanged = -1;
        for ($position = 0; $position < count($chain); $position++) {
            $layer = $chain[$position];
            if ($layer['change'] === FlowScenarioChange::UNCHANGED) {
                $label = match (true) {
                    preg_match(self::RESULT, $layer['layer']) === 1, preg_match(self::THEN_TO, $layer['layer']) === 1 => null,
                    preg_match(self::CONSUMER, $layer['layer']) === 1 => 'consumer',
                    preg_match(self::INTERCEPTOR, $layer['layer']) === 1 => 'interceptors (unchanged)',
                    default => '…',
                };
                if ($label !== null && ($layers === [] || $layers[count($layers) - 1]['text'] !== $label)) {
                    $layers[] = ['text' => $label];
                }
                continue;
            }
            $run = [];
            while ($position < count($chain) && $chain[$position]['change'] !== FlowScenarioChange::UNCHANGED) {
                $run[] = $chain[$position++];
            }
            $position--;
            $layers = [...$layers, ...$this->changedRun($run)];
            $lastChanged = count($layers) - 1;
        }

        return array_slice($layers, 0, $lastChanged + 1);
    }

    /**
     * @param list<array{change: string, layer: string}> $run
     * @return list<array{text: string, lines?: list<string>}>
     */
    private function changedRun(array $run): array
    {
        $removed = array_values(array_filter($run, fn (array $layer): bool => $layer['change'] === FlowScenarioChange::REMOVED));
        $added = array_values(array_filter($run, fn (array $layer): bool => $layer['change'] === FlowScenarioChange::ADDED));
        if (count($removed) === 1 && count($added) === 1 && preg_match(self::INVOCATION, $removed[0]['layer'], $before) === 1 && preg_match(self::INVOCATION, $added[0]['layer'], $after) === 1 && [$before[1], $before[2]] === [$after[1], $after[2]]) {
            $parametersBefore = $before[3] === '' ? [] : explode(', ', $before[3]);
            $parametersAfter = $after[3] === '' ? [] : explode(', ', $after[3]);
            if (array_values(array_intersect($parametersBefore, $parametersAfter)) === array_values(array_intersect($parametersAfter, $parametersBefore))) {
                $parameters = [
                    ...array_map(fn (string $parameter): string => '- ' . FlowWording::withShortClassNames($parameter), array_values(array_diff($parametersBefore, $parametersAfter))),
                    ...array_map(fn (string $parameter): string => '+ ' . FlowWording::withShortClassNames($parameter), array_values(array_diff($parametersAfter, $parametersBefore))),
                ];

                return [['text' => $this->readable($before[1] . $before[2] . '(…)')], ['text' => $parameters[0], 'lines' => array_slice($parameters, 1)]];
            }
        }
        $lines = array_map(fn (array $layer): string => ($layer['change'] === FlowScenarioChange::ADDED ? '+ ' : '- ') . $this->readable($layer['layer']), $run);

        return [['text' => $lines[0], 'lines' => array_slice($lines, 1)]];
    }

    /**
     * @param list<array{change: string, layer: string}> $chain
     * @return list<array{change: string, layer: string}>
     */
    private function withTheStandardResultAsOneLayer(array $chain): array
    {
        $folded = [];
        for ($position = 0; $position < count($chain); $position++) {
            [$first, $second, $third] = [$chain[$position], $chain[$position + 1] ?? null, $chain[$position + 2] ?? null];
            if ($second !== null && $third !== null && $first['layer'] === self::STANDARD_RESULT[0] && $third['layer'] === self::STANDARD_RESULT[1] && $first['change'] === $second['change'] && $second['change'] === $third['change']) {
                $folded[] = ['change' => $first['change'], 'layer' => 'reply channel of the message'];
                $position += 2;
            } else {
                $folded[] = $first;
            }
        }

        return $folded;
    }

    private function readable(string $layer): string
    {
        return FlowWording::withShortClassNames(match (true) {
            preg_match(self::CONSUMER, $layer, $consumer) === 1 => "consumer ({$consumer[1]})",
            preg_match(self::THEN_TO, $layer, $channel) === 1 => 'then to ' . $channel[1],
            preg_match('/^(.+) \(\S+, with an Enterprise licence only\)$/', $layer, $enterprise) === 1 => $enterprise[1] . ' (Enterprise only)',
            $layer === 'reply channel of the message' => 'result to the routing slip, a part report or the reply channel',
            str_starts_with($layer, 'handler ') => substr($layer, strlen('handler ')),
            default => $layer,
        });
    }

    /**
     * @param list<array{text: string, lines?: list<string>}> $layers
     * @return list<array<string, mixed>>
     */
    private function nested(array $layers): array
    {
        $innermost = [];
        foreach (array_reverse($layers) as $layer) {
            $innermost = [[...$layer, 'children' => $innermost]];
        }

        return $innermost;
    }

    /**
     * @param list<array{text: string, lines?: list<string>, marked?: bool, children?: list<array<string, mixed>>}> $nodes
     * @return list<string>
     */
    private function lines(array $nodes, string $indentation): array
    {
        $lines = [];
        foreach ($nodes as $position => $node) {
            $last = $position === array_key_last($nodes);
            $below = $indentation . ($last ? '  ' : '│ ');
            $lines[] = $indentation . ($last ? '└ ' : '├ ') . $node['text'];
            foreach ($node['lines'] ?? [] as $line) {
                $lines[] = $below . (($node['marked'] ?? false) ? '  ' : '') . $line;
            }
            $lines = [...$lines, ...$this->lines($node['children'] ?? [], $below . (($node['marked'] ?? false) ? '  ' : ''))];
        }

        return $lines;
    }
}
