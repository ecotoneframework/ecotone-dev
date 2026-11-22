<?php

declare(strict_types=1);

namespace Test\Ecotone\BuildFlow;

use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;

/**
 * licence Apache-2.0
 */
final class FlowWording
{
    private const CONSOLE_COMMAND_ENDPOINT = 'endpoint:ecotone.endpoint.';
    private const CONSOLE_COMMAND_CHANNEL = 'ecotone.channel.';
    private const BUS_CHANNEL = '/^ecotone\.modelling\.bus\.(command|event|query)_by_name$/';
    private const DESCRIBED_CLASSES = [
        'Ecotone\Messaging\Handler\Bridge\Bridge' => 'consumer of %s',
        'Ecotone\Messaging\Handler\Transformer\RoutingSlipPrepender' => 'asynchronous routing of %s',
        'Ecotone\Api\Gateway\GatheredGroups' => 'closed-group cleanup',
    ];

    public static function ofNode(FlowNode $node, MessageFlowGraph $wiring): string
    {
        $class = (string) ($node->attributes['class'] ?? '');
        $method = (string) ($node->attributes['method'] ?? '');
        $inputChannel = (string) ($node->attributes['inputChannel'] ?? '');

        return match (true) {
            str_starts_with($node->id, self::CONSOLE_COMMAND_ENDPOINT) => 'console command ' . substr($node->id, strlen(self::CONSOLE_COMMAND_ENDPOINT)),
            $node->kind === FlowNodeKind::CHANNEL && str_starts_with($node->label, self::CONSOLE_COMMAND_CHANNEL) => 'channel of console command ' . substr($node->label, strlen(self::CONSOLE_COMMAND_CHANNEL)),
            $node->kind === FlowNodeKind::CHANNEL => $node->label . (self::isEntryPoint($node, $wiring) ? ' (entry point: sent to from code)' : ''),
            isset(self::DESCRIBED_CLASSES[$class]) => sprintf(self::DESCRIBED_CLASSES[$class], $inputChannel),
            preg_match(self::BUS_CHANNEL, $inputChannel, $bus) === 1 => $bus[1] . ' bus routing',
            str_contains($class, '@anonymous') && $method !== '' => self::ofAnonymousHandler($node, $wiring, withItsKind: false),
            ($node->attributes['framework'] ?? false) === true && $class !== '' && $method !== '' => self::shortName($class) . '::' . $method,
            default => $node->label,
        };
    }

    /**
     * @param array<string, mixed> $step
     */
    public static function ofStep(array $step, MessageFlowGraph $wiring): string
    {
        $name = (string) $step['name'];
        if (in_array($name, ['send command', 'send event', 'send query'], true) && isset($step['destination'])) {
            return $name . ' ' . $step['destination'];
        }
        if (preg_match('/^process ([^ ]*@anonymous[^ :]*)::(\w+)$/', $name, $handler) !== 1) {
            return $name;
        }
        foreach ($wiring->nodes() as $node) {
            if (($node->attributes['method'] ?? null) === $handler[2] && str_contains((string) ($node->attributes['class'] ?? ''), '@anonymous') && $node->kind !== FlowNodeKind::INTERCEPTOR) {
                return 'process ' . self::ofAnonymousHandler($node, $wiring, withItsKind: true);
            }
        }

        return 'process ' . $handler[2];
    }

    /**
     * @param array<string, scalar> $entry
     */
    public static function ofRegistration(array $entry): string
    {
        $name = self::withShortClassNames((string) $entry['name']);

        return match ($entry['kind']) {
            'handler' => "handler {$name}: {$entry['builder']} on " . ($entry['inputChannel'] ?? 'no channel')
                . (isset($entry['outputChannel']) ? ", then to {$entry['outputChannel']}" : '')
                . (isset($entry['target']) && self::withShortClassNames((string) $entry['target']) !== $name ? ', invokes ' . self::withShortClassNames((string) $entry['target']) : ''),
            'gateway' => "gateway {$name}, sends to " . ($entry['requestChannel'] ?? 'no channel'),
            'channel' => "channel {$name} (" . (($entry['pollable'] ?? false) ? 'pollable' : 'subscribable') . ')',
            'consumer' => "consumer {$name} (" . ($entry['builder'] ?? 'consumer') . ')',
            'interceptor' => ($entry['position'] ?? '') . ' interceptor ' . self::withShortClassNames((string) ($entry['target'] ?? $entry['name'])),
            'service' => "service {$name}" . match (true) {
                isset($entry['enterprise']) => ': Enterprise -> ' . self::withShortClassNames((string) $entry['enterprise']) . ', open core -> ' . self::withShortClassNames((string) $entry['openCore']),
                isset($entry['aliasOf']) => ': alias of ' . self::withShortClassNames((string) $entry['aliasOf']),
                self::withShortClassNames((string) ($entry['class'] ?? $entry['name'])) !== $name => ': ' . self::withShortClassNames((string) $entry['class']),
                default => '',
            },
            default => $entry['kind'] . ' ' . $name,
        };
    }

    public static function withShortClassNames(string $text): string
    {
        return (string) preg_replace('/(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+(?=[A-Za-z_])/', '', $text);
    }

    public static function ofArrow(FlowEdgeKind $kind): string
    {
        return match ($kind) {
            FlowEdgeKind::OUTPUT => 'then sends to',
            FlowEdgeKind::ROUTES_TO => 'routed to',
            FlowEdgeKind::INPUT => 'consumed by',
            FlowEdgeKind::INTERCEPTED_BY => 'intercepted by',
            FlowEdgeKind::ASYNCHRONOUS => 'asynchronously to',
            default => (string) preg_replace_callback('/(?<=[a-z])[A-Z]/', fn (array $letter): string => ' ' . strtolower($letter[0]), $kind->value),
        };
    }

    /**
     * @param array{change: string, step: string, steps: int, times: int, movement: string, parent: string, changedInside: bool} $moved
     */
    public static function ofMovedSteps(array $moved): string
    {
        $where = ($moved['movement'] === FlowExecutionChange::UNDER ? 'now run under the ' : 'no longer run under the ') . self::stepNameOf($moved['parent']) . ' step';

        return str_repeat(' ', strspn($moved['step'], ' '))
            . '… ' . ($moved['times'] > 1 ? $moved['times'] . ' × ' : '') . ltrim($moved['step'], ' ')
            . ' (' . $moved['steps'] . ' step' . ($moved['steps'] === 1 ? '' : 's') . ($moved['times'] > 1 ? ' each' : '') . '): '
            . ($moved['changedInside'] ? 'moved, ' . $where . ', and inside it:' : 'unchanged, ' . $where);
    }

    /**
     * @param list<array{process: string, change: string, sentBy?: string, sentAt?: int, sentByBefore?: ?string}> $execution
     */
    public static function ofSubFlowChanges(array $execution): string
    {
        $counted = [];
        $moved = [];
        foreach ($execution as $process) {
            if (array_key_exists('sentByBefore', $process)) {
                $moved[] = '`' . $process['process'] . '` is ' . match (true) {
                    ! isset($process['sentBy']) => 'no longer a sub-flow, before of `' . $process['sentByBefore'] . '`',
                    $process['sentByBefore'] === null => 'now a sub-flow of `' . $process['sentBy'] . '`',
                    default => 'now a sub-flow of `' . $process['sentBy'] . '`, before of `' . $process['sentByBefore'] . '`',
                };
                continue;
            }
            if (isset($process['sentBy']) && in_array($process['change'], [FlowScenarioChange::ADDED, FlowScenarioChange::REMOVED], true)) {
                $sender = (string) preg_replace('/ \((?:part )?\d+ of \d+\)$/', '', $process['sentBy']);
                $counted[$process['change']][$sender] = ($counted[$process['change']][$sender] ?? 0) + 1;
            }
        }
        $sentences = [
            ...array_map(fn (string $sender, int $count): string => $count . ' new sub-flow' . ($count === 1 ? '' : 's') . ' of `' . $sender . '`', array_keys($counted[FlowScenarioChange::ADDED] ?? []), $counted[FlowScenarioChange::ADDED] ?? []),
            ...array_map(fn (string $sender, int $count): string => $count . ' sub-flow' . ($count === 1 ? '' : 's') . ' of `' . $sender . '` gone', array_keys($counted[FlowScenarioChange::REMOVED] ?? []), $counted[FlowScenarioChange::REMOVED] ?? []),
            ...$moved,
        ];

        return $sentences === [] ? '' : implode('; ', $sentences) . '.';
    }

    /**
     * @param list<array{change: string, step?: string, steps?: int, times?: int, movement?: string, parent?: string, changedInside?: bool}> $steps
     */
    public static function ofChangedProcess(array $steps): string
    {
        $moved = array_filter($steps, fn (array $entry): bool => $entry['change'] === FlowScenarioChange::MOVED);
        $runsOtherSteps = array_filter($steps, fn (array $entry): bool => in_array($entry['change'], [FlowScenarioChange::ADDED, FlowScenarioChange::REMOVED], true)) !== [];
        $rehinted = count(array_filter($steps, fn (array $entry): bool => $entry['change'] === FlowScenarioChange::CHANGED || isset($entry['hintChanges'])));
        if ($moved === []) {
            return $runsOtherSteps || $rehinted === 0 ? 'runs other steps now.' : "runs the same steps, {$rehinted} of them with other hints.";
        }
        $count = array_sum(array_map(fn (array $entry): int => $entry['steps'] * $entry['times'], $moved));
        $movements = array_unique(array_column($moved, 'movement'));
        $where = match (true) {
            count($movements) > 1 => 'under or out of another step',
            reset($movements) === FlowExecutionChange::UNDER => 'under another step',
            default => 'outside the step they ran under',
        };

        return $runsOtherSteps
            ? "runs other steps now, and {$count} of its steps now run {$where}."
            : "runs the same steps, {$count} of them now {$where}.";
    }

    private static function stepNameOf(string $step): string
    {
        $words = explode(' ', $step);
        $named = [];
        foreach ($words as $word) {
            $named[] = $word;
            if (str_contains($word, '::')) {
                return implode(' ', $named);
            }
        }

        return $words[0];
    }

    private static function ofAnonymousHandler(FlowNode $node, MessageFlowGraph $wiring, bool $withItsKind): string
    {
        $method = (string) $node->attributes['method'];
        $ownerId = $node->attributes['owner'] ?? null;
        if (is_string($ownerId) && $wiring->hasNode($ownerId)) {
            $owner = $wiring->node($ownerId);

            return "{$method} of {$owner->kind->value} {$owner->label}";
        }

        return $withItsKind ? strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $node->kind->value)) . ' ' . $method : $method;
    }

    private static function isEntryPoint(FlowNode $channel, MessageFlowGraph $wiring): bool
    {
        return ($channel->attributes['framework'] ?? false) === true && $wiring->hasNode($channel->id) && $wiring->edgesTo($channel->id) === [];
    }

    private static function shortName(string $class): string
    {
        $segments = explode('\\', $class);

        return end($segments);
    }
}
