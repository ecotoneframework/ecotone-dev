<?php

declare(strict_types=1);

namespace App\Ui;

use App\Graph\DistributedLink;
use App\Graph\DistributedLinks;
use App\Graph\ServiceFlow;
use App\Graph\ShortLabel;
use App\Graph\TraceHighlight;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;

final readonly class FlowGraphSummary
{
    private const RUNTIME_KEY_CALLS = ['sendCommand', 'convertAndSendCommand', 'sendMessage', 'publishEvent', 'convertAndPublishEvent'];
    private const EVENT_CALLS = ['publishEvent', 'convertAndPublishEvent'];
    private const LINK_NOUNS = ['command' => ['command', 'commands'], 'event' => ['event', 'events'], 'message' => ['message', 'messages'], 'reply' => ['reply', 'replies']];

    /**
     * @param list<array{kind: string, status: Status, text: string, where: list<array{label: string, title?: string}>, evidence: string, concept: string, follow: array{service: string, node: string, label: string}}> $findings
     */
    private function __construct(
        public Status $status,
        public ?string $reason,
        public string $sentence,
        public string $graphSentence,
        public array $findings,
        public bool $checked,
    ) {
    }

    /**
     * @param list<ServiceFlow> $flows
     * @param list<string> $describedServices
     * @param list<array{kind: string, message: string}> $notices
     */
    public static function of(array $flows, array $describedServices, ?TraceHighlight $highlight, bool $traceEmpty, bool $waiting, array $notices): self
    {
        $shown = array_values(array_filter($flows, static fn (ServiceFlow $flow): bool => in_array($flow->name, $describedServices, true)));
        $links = DistributedLinks::between($flows);
        $graphSentence = self::graphSentenceOf($shown, $links);
        $findings = [
            ...($highlight === null ? [] : self::traceFailuresOf($flows, $highlight)),
            ...self::unhandledOf($shown),
            ...self::unknownSendersOf($shown, $links),
            ...self::runtimeRoutingKeysOf($shown),
        ];
        $status = Status::worstOf(...array_map(static fn (array $finding): Status => $finding['status'], $findings), ...($notices === [] ? [] : [Status::Warning]));
        if ($highlight === null && $shown === []) {
            return new self(Status::Unknown, 'no service shown here has answered Pulse with its flow graph', $graphSentence, $graphSentence, [], false);
        }
        if ($highlight === null) {
            return new self($status, null, $graphSentence, $graphSentence, $findings, $findings === [] && $notices === []);
        }
        [$traceStatus, $reason, $sentence] = self::traceVerdictOf($highlight, $traceEmpty, $waiting);

        return new self($traceStatus, $reason, $sentence, $graphSentence, $findings, false);
    }

    /**
     * @param list<ServiceFlow> $shown
     * @param list<DistributedLink> $links
     */
    private static function graphSentenceOf(array $shown, array $links): string
    {
        if ($shown === []) {
            return 'No service has answered with its flow graph yet.';
        }
        $types = ['command' => [], 'event' => [], 'query' => []];
        foreach ($shown as $flow) {
            foreach ($flow->graph->nodesOfKind(FlowNodeKind::COMMAND, FlowNodeKind::EVENT, FlowNodeKind::QUERY) as $node) {
                $types[$node->kind->value][ServiceFlow::routingKeyOf($node)] = true;
            }
        }
        $names = array_map(static fn (ServiceFlow $flow): string => $flow->name, $shown);
        $total = count($types['command']) + count($types['event']) + count($types['query']);
        $sentence = sprintf(
            '%s %s %d message %s (%s, %s, %s)',
            count($names) > 3 ? count($names) . ' services' : self::listed($names),
            count($names) === 1 ? 'knows' : 'know',
            $total,
            $total === 1 ? 'type' : 'types',
            self::counted(count($types['command']), 'command', 'commands'),
            self::counted(count($types['event']), 'event', 'events'),
            self::counted(count($types['query']), 'query', 'queries'),
        );
        $crossing = [];
        $partners = [];
        foreach ($links as $link) {
            $from = in_array($link->fromService, $names, true);
            $to = in_array($link->toService, $names, true);
            if (count($names) === 1 ? $from !== $to : $from && $to) {
                $crossing[$link->kind] = ($crossing[$link->kind] ?? 0) + 1;
                $partners[$from ? $link->toService : $link->fromService] = true;
            }
        }
        if ($crossing === []) {
            return $sentence . (count($names) === 1 ? '; no message crosses the Distributed Bus to or from it.' : '; no message crosses the Distributed Bus between them.');
        }
        $parts = [];
        foreach (self::LINK_NOUNS as $kind => [$one, $many]) {
            if (isset($crossing[$kind])) {
                $parts[] = self::counted($crossing[$kind], $one, $many);
            }
        }

        return sprintf(
            '%s; %s %s the Distributed Bus%s.',
            $sentence,
            self::listed($parts),
            array_sum($crossing) === 1 ? 'crosses' : 'cross',
            count($names) === 1 ? ' between it and ' . self::listed(array_map('strval', array_keys($partners))) : '',
        );
    }

    /**
     * @return array{0: Status, 1: ?string, 2: string}
     */
    private static function traceVerdictOf(TraceHighlight $highlight, bool $traceEmpty, bool $waiting): array
    {
        $trace = 'Trace ' . (strlen($highlight->traceId) > 8 ? substr($highlight->traceId, 0, 8) . '…' : $highlight->traceId);
        if ($traceEmpty) {
            return $waiting
                ? [Status::Info, null, $trace . ' is not drawn yet: Pulse is waiting for the services to answer.']
                : [Status::Unknown, 'no service that answered stored a step of this trace id', $trace . ' is not on the graph.'];
        }
        $counts = $highlight->countsByState();
        $passed = $counts[TraceHighlight::PASSED];
        if ($highlight->status === 'failed') {
            return [Status::Warning, null, sprintf('%s failed: %s failed and %d passed; the red nodes are where it failed.', $trace, self::counted($counts[TraceHighlight::FAILED], 'node', 'nodes'), $passed)];
        }
        if ($highlight->status === 'pending') {
            return [Status::Info, null, sprintf('%s is still running: %s wait to be processed (amber) and %d passed.', $trace, self::counted($counts[TraceHighlight::PENDING], 'node', 'nodes'), $passed)];
        }

        return [Status::Ok, null, sprintf('%s passed through %s in %s; green arrows show the way it went.', $trace, self::counted($passed, 'node', 'nodes'), self::listed($highlight->services))];
    }

    /**
     * @param list<ServiceFlow> $flows
     * @return list<array{kind: string, status: Status, text: string, where: list<array{label: string, title?: string}>, evidence: string, concept: string, follow: array{service: string, node: string, label: string}}>
     */
    private static function traceFailuresOf(array $flows, TraceHighlight $highlight): array
    {
        $failed = $highlight->nodesInState(TraceHighlight::FAILED);
        $findings = [];
        foreach ($flows as $flow) {
            $nodes = array_map(static fn (string $id): FlowNode => $flow->graph->node($id), $failed[$flow->name] ?? []);
            $handlers = array_values(array_filter($nodes, static fn (FlowNode $node): bool => $node->kind->isHandler()));
            $nodes = $handlers === [] ? $nodes : $handlers;
            if ($nodes === []) {
                continue;
            }
            $labels = array_map(static fn (FlowNode $node): string => ShortLabel::of($node->label), $nodes);
            $findings[] = [
                'kind' => 'trace-failed',
                'status' => Status::Warning,
                'text' => count($nodes) === 1
                    ? sprintf('%s failed in %s in this trace.', $labels[0], $flow->name)
                    : sprintf('%d steps failed in %s in this trace: %s.', count($nodes), $flow->name, implode(', ', $labels)),
                'where' => [['label' => $flow->name], ['label' => $labels[0], 'title' => $nodes[0]->label]],
                'evidence' => 'Red marks the step whose span recorded an error; the trace shows the exception and, when the message was kept, its dead letter.',
                'concept' => 'trace',
                'follow' => ['service' => $flow->name, 'node' => $nodes[0]->id, 'label' => $labels[0]],
            ];
        }

        return $findings;
    }

    /**
     * @param list<ServiceFlow> $shown
     * @return list<array{kind: string, status: Status, text: string, where: list<array{label: string, title?: string}>, evidence: string, concept: string, follow: array{service: string, node: string, label: string}}>
     */
    private static function unhandledOf(array $shown): array
    {
        $findings = [];
        foreach ($shown as $flow) {
            $unhandled = array_values(array_filter(
                $flow->graph->nodesOfKind(FlowNodeKind::COMMAND, FlowNodeKind::QUERY),
                static fn (FlowNode $node): bool => ($node->attributes['handled'] ?? null) === false && ($node->attributes['distributed'] ?? false) !== true,
            ));
            if ($unhandled === []) {
                continue;
            }
            $senders = [];
            $declared = false;
            foreach ($unhandled as $node) {
                foreach ($flow->graph->edgesTo($node->id) as $edge) {
                    $declared = $declared || ! $edge->isInferred();
                    $sender = $flow->graph->node($edge->from);
                    if ($sender->kind->isHandler()) {
                        $senders[ShortLabel::of($sender->label)] = true;
                    }
                }
            }
            $kinds = array_values(array_unique(array_map(static fn (FlowNode $node): string => $node->kind->value, $unhandled)));
            [$one, $many, $attribute] = match ($kinds) {
                ['command'] => ['command', 'commands', '#[CommandHandler]'],
                ['query'] => ['query', 'queries', '#[QueryHandler]'],
                default => ['message', 'messages', '#[CommandHandler] or #[QueryHandler]'],
            };
            $labels = array_map(static fn (FlowNode $node): string => ShortLabel::of($node->label), $unhandled);
            $findings[] = [
                'kind' => 'unhandled-message',
                'status' => $declared ? Status::Warning : Status::Info,
                'text' => sprintf('%s sends %s that no handler in %s takes: %s.', $flow->name, self::counted(count($unhandled), $one, $many), $flow->name, implode(', ', $labels)),
                'where' => [['label' => $flow->name], ['label' => $labels[0], 'title' => $unhandled[0]->label]],
                'evidence' => sprintf(
                    'The send is inferred from the source code of %s. If it is a message, add its %s; if it is not, the inference read a value passed to a bus as one.',
                    $senders === [] ? 'a handler' : self::listedBriefly(array_map('strval', array_keys($senders))),
                    $attribute,
                ),
                'concept' => 'handler',
                'follow' => ['service' => $flow->name, 'node' => $unhandled[0]->id, 'label' => $labels[0]],
            ];
        }

        return $findings;
    }

    /**
     * @param list<ServiceFlow> $shown
     * @param list<DistributedLink> $links
     * @return list<array{kind: string, status: Status, text: string, where: list<array{label: string, title?: string}>, evidence: string, concept: string, follow: array{service: string, node: string, label: string}}>
     */
    private static function unknownSendersOf(array $shown, array $links): array
    {
        $reached = [];
        foreach ($links as $link) {
            if ($link->kind !== 'reply') {
                $reached[$link->toService][$link->toNodeId] = true;
            }
        }
        $findings = [];
        foreach ($shown as $flow) {
            $unreached = [];
            foreach ($flow->distributedReceivers() as $received) {
                $unreached[$received['node']->id] ??= ['node' => $received['node'], 'routingKey' => $received['routingKey']];
            }
            foreach ($flow->distributedMessageReceivers() as $received) {
                $unreached[$received['target']->id] ??= ['node' => $received['target'], 'routingKey' => $received['routingKey']];
            }
            $unreached = array_values(array_filter($unreached, static fn (array $received): bool => ! isset($reached[$flow->name][$received['node']->id])));
            if ($unreached === []) {
                continue;
            }
            $keys = array_values(array_unique(array_map(static fn (array $received): string => $received['routingKey'], $unreached)));
            $findings[] = [
                'kind' => 'unknown-sender',
                'status' => Status::Info,
                'text' => sprintf('%s receives %s over the Distributed Bus that no service is seen sending: %s.', $flow->name, self::counted(count($keys), 'message', 'messages'), implode(', ', $keys)),
                'where' => [['label' => $flow->name], ['label' => $keys[0]]],
                'evidence' => 'Senders are inferred from the source code of handlers, so a send from a controller or a console command, with the routing key in a variable, or from a service Pulse does not watch draws no arrow.',
                'concept' => 'distributed-bus',
                'follow' => ['service' => $flow->name, 'node' => $unreached[0]['node']->id, 'label' => $keys[0]],
            ];
        }

        return $findings;
    }

    /**
     * @param list<ServiceFlow> $shown
     * @return list<array{kind: string, status: Status, text: string, where: list<array{label: string, title?: string}>, evidence: string, concept: string, follow: array{service: string, node: string, label: string}}>
     */
    private static function runtimeRoutingKeysOf(array $shown): array
    {
        $findings = [];
        foreach ($shown as $flow) {
            $groups = [];
            foreach ($flow->graph->edgesOfKind(FlowEdgeKind::SENDS_TO) as $edge) {
                $via = $edge->attributes['via'] ?? null;
                $sender = $flow->graph->node($edge->from);
                if (! $edge->isInferred() || ! in_array($via, self::RUNTIME_KEY_CALLS, true) || is_string($edge->attributes['targetChannel'] ?? null) || ! $sender->kind->isHandler() || $flow->graph->node($edge->to)->kind !== FlowNodeKind::DISTRIBUTED_BUS) {
                    continue;
                }
                $target = $edge->attributes['targetService'] ?? null;
                $group = in_array($via, self::EVENT_CALLS, true) ? 'event' : (is_string($target) ? 'to:' . $target : 'unknown');
                $groups[$group][$sender->id] = $sender;
            }
            foreach ($groups as $group => $senders) {
                $senders = array_values($senders);
                $labels = array_map(static fn (FlowNode $node): string => ShortLabel::of($node->label), $senders);
                $count = count($senders);
                $sends = match (true) {
                    $group === 'event' => sprintf('publishes %s with a routing key computed at runtime, so %s', self::counted($count, 'event', 'events'), $count === 1 ? 'its arrow stops' : 'their arrows stop'),
                    $group === 'unknown' => sprintf('sends %s with a target service or routing key computed at runtime, so %s', self::counted($count, 'message', 'messages'), $count === 1 ? 'its arrow stops' : 'their arrows stop'),
                    default => sprintf('sends %s to %s with a routing key computed at runtime, so %s', self::counted($count, 'message', 'messages'), substr($group, 3), $count === 1 ? 'its arrow stops' : 'their arrows stop'),
                };
                $findings[] = [
                    'kind' => 'runtime-routing-key',
                    'status' => Status::Info,
                    'text' => sprintf('%s %s at the Distributed Bus: from %s.', $flow->name, $sends, implode(', ', $labels)),
                    'where' => [['label' => $flow->name], ['label' => $labels[0], 'title' => $senders[0]->label]],
                    'evidence' => 'Pulse sees that the handler sends, but not which message, so the receiving handler shows no incoming arrow. A literal routing key, or a trace highlighting a run, shows the whole path.',
                    'concept' => 'inferred-arrow',
                    'follow' => ['service' => $flow->name, 'node' => $senders[0]->id, 'label' => $labels[0]],
                ];
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $items
     */
    private static function listedBriefly(array $items): string
    {
        return count($items) <= 3 ? self::listed($items) : sprintf('%s and %d more', implode(', ', array_slice($items, 0, 2)), count($items) - 2);
    }

    private static function counted(int $count, string $one, string $many): string
    {
        return $count . ' ' . ($count === 1 ? $one : $many);
    }

    /**
     * @param list<string> $items
     */
    private static function listed(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }

        return implode(', ', array_slice($items, 0, -1)) . ' and ' . $items[count($items) - 1];
    }
}
