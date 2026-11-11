<?php

declare(strict_types=1);

namespace App\Ui;

use App\DeadLetter\DeadLetterFilter;
use App\DeadLetter\DeadLetterOverview;
use App\Graph\DiagramNode;
use App\Graph\DistributedLinks;
use App\Graph\ServiceFlow;
use App\Graph\ShortLabel;
use App\Graph\StitchedFlowGraph;
use App\Snapshot\ChannelDepth;
use App\Snapshot\ServiceSnapshot;
use App\Trace\FlowTypeOverview;
use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;

final readonly class CatalogueNode
{
    private const FACTS = [
        'class' => 'Class',
        'interface' => 'Interface',
        'method' => 'Method',
        'routingKey' => 'Routing key',
        'routingKeys' => 'Routing keys',
        'namedAs' => 'Named as',
        'endpointId' => 'Endpoint id',
        'name' => 'Channel name',
        'requestChannel' => 'Request channel',
        'reference' => 'Reference',
        'targetService' => 'Sent to service',
        'position' => 'Position',
        'pointcut' => 'Pointcut',
    ];

    /**
     * @param list<array{term: string, value: string}> $facts
     * @param list<array{service: string, nodeId: string, kind: string, kindLabel: string, family: string, label: string, relation: string, runs: ?string}> $incoming
     * @param list<array{service: string, nodeId: string, kind: string, kindLabel: string, family: string, label: string, relation: string, runs: ?string}> $outgoing
     * @param array{service: string, flowType: string, runs: int, runsWithErrors: int, p50Ms: ?float, p95Ms: ?float, maxMs: ?float}|null $flowType
     * @param array{messageName: string, count: int}|null $deadLetters
     */
    private function __construct(
        public string $service,
        public string $nodeId,
        public string $kind,
        public string $kindLabel,
        public string $family,
        public string $label,
        public string $fullLabel,
        public string $sentence,
        public array $facts,
        public array $incoming,
        public array $outgoing,
        public ?array $flowType,
        public ?array $deadLetters,
        public ?ChannelDepth $depth,
    ) {
    }

    /**
     * @param list<ServiceFlow> $flows
     * @param list<ServiceSnapshot> $snapshots
     * @param list<ChannelDepth> $depths
     */
    public static function of(array $flows, ServiceFlow $flow, FlowNode $node, array $snapshots, array $depths): self
    {
        $shape = new DiagramNode('', null, $node->kind->value, ShortLabel::of($node->label));
        $incoming = [];
        $outgoing = [];
        foreach ($flow->graph->edgesTo($node->id) as $edge) {
            $incoming[] = self::related($flow, $flow->graph->node($edge->from), self::relationOf($edge, $node));
        }
        foreach ($flow->graph->edgesFrom($node->id) as $edge) {
            $outgoing[] = self::related($flow, $flow->graph->node($edge->to), self::relationOf($edge, $flow->graph->node($edge->to)));
        }
        $byName = [];
        foreach ($flows as $other) {
            $byName[$other->name] = $other;
        }
        foreach (DistributedLinks::between($flows) as $link) {
            if ($link->toService === $flow->name && $link->toNodeId === $node->id && isset($byName[$link->fromService]) && $byName[$link->fromService]->graph->hasNode($link->fromNodeId)) {
                $incoming[] = self::related($byName[$link->fromService], $byName[$link->fromService]->graph->node($link->fromNodeId), $link->label());
            }
            if ($link->fromService === $flow->name && $link->fromNodeId === $node->id && isset($byName[$link->toService]) && $byName[$link->toService]->graph->hasNode($link->toNodeId)) {
                $receiver = $byName[$link->toService];
                $received = $receiver->graph->node($link->toNodeId);
                $outgoing[] = self::related($receiver, $received, $link->label());
                if ($received->kind->isMessage()) {
                    foreach ($receiver->graph->edgesOfKind(FlowEdgeKind::ROUTED_TO, from: $received->id) as $routed) {
                        $outgoing[] = self::related($receiver, $receiver->graph->node($routed->to), 'handles it in ' . $receiver->name);
                    }
                }
            }
        }
        $depth = null;
        foreach ($depths as $candidate) {
            if ($node->kind === FlowNodeKind::CHANNEL && $candidate->name === $node->label) {
                $depth = $candidate;
            }
        }

        return new self(
            $flow->name,
            $node->id,
            $node->kind->value,
            $shape->kindLabel(),
            $shape->family(),
            ShortLabel::of($node->label),
            $node->label,
            self::sentenceOf($flow, $node, $incoming, $outgoing),
            self::factsOf($flow, $node),
            self::distinct($incoming),
            self::distinct($outgoing),
            $node->kind->isMessage() ? self::flowTypeOf($snapshots, $flow->name, $node) : null,
            $node->kind->isMessage() ? self::deadLettersOf($snapshots, $flow->name, $node) : null,
            $depth,
        );
    }

    /**
     * @return array{service: string, nodeId: string, kind: string, kindLabel: string, family: string, label: string, relation: string, runs: ?string}
     */
    private static function related(ServiceFlow $flow, FlowNode $node, string $relation): array
    {
        $shape = new DiagramNode('', null, $node->kind->value, ShortLabel::of($node->label));

        return ['service' => $flow->name, 'nodeId' => $node->id, 'kind' => $node->kind->value, 'kindLabel' => $shape->kindLabel(), 'family' => $shape->family(), 'label' => $shape->label, 'relation' => $relation, 'runs' => $node->kind->isHandler() ? self::runsOf($flow, $node) : null];
    }

    private static function runsOf(ServiceFlow $flow, FlowNode $handler): string
    {
        $channels = $flow->graph->edgesOfKind(FlowEdgeKind::ASYNCHRONOUS, to: $handler->id);

        return match (true) {
            $channels !== [] => 'asynchronously, on channel ' . $flow->graph->node($channels[0]->from)->label,
            ($handler->attributes['distributed'] ?? false) === true => 'in ' . $flow->name . ' as soon as the message arrives over the Distributed Bus',
            default => 'synchronously, in the process that sends the message',
        };
    }

    private static function relationOf(FlowEdge $edge, FlowNode $target): string
    {
        $label = StitchedFlowGraph::arrow($edge, $target)[1];
        $relation = $label !== '' ? $label : strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $edge->kind->value)));

        return $relation . ($edge->isInferred() ? ', read from source code' : '');
    }

    /**
     * @param list<array{service: string, nodeId: string, kind: string, kindLabel: string, family: string, label: string, relation: string, runs: ?string}> $related
     * @return list<array{service: string, nodeId: string, kind: string, kindLabel: string, family: string, label: string, relation: string, runs: ?string}>
     */
    private static function distinct(array $related): array
    {
        $distinct = [];
        foreach ($related as $one) {
            $distinct[$one['service'] . '|' . $one['nodeId'] . '|' . $one['relation']] = $one;
        }

        return array_values($distinct);
    }

    /**
     * @return list<array{term: string, value: string}>
     */
    private static function factsOf(ServiceFlow $flow, FlowNode $node): array
    {
        $facts = [['term' => 'Service', 'value' => $flow->name]];
        foreach (self::FACTS as $attribute => $term) {
            $value = $node->attributes[$attribute] ?? null;
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, is_string(...)));
            }
            if (is_string($value) && $value !== '') {
                $facts[] = ['term' => $term, 'value' => $value];
            }
        }
        if ($node->kind->isHandler()) {
            $facts[] = ['term' => 'Runs', 'value' => self::runsOf($flow, $node)];
        }
        if (($node->attributes['distributed'] ?? false) === true) {
            $facts[] = ['term' => 'Distributed', 'value' => 'yes, other services reach it over the Distributed Bus'];
        }
        if ($node->kind === FlowNodeKind::CHANNEL && is_bool($node->attributes['pollable'] ?? null)) {
            $facts[] = ['term' => 'Pollable', 'value' => $node->attributes['pollable'] ? 'yes, a consumer has to run to drain it' : 'no, messages are handled as they are sent'];
        }
        if (is_bool($node->attributes['eventSourced'] ?? null)) {
            $facts[] = ['term' => 'Storage', 'value' => $node->attributes['eventSourced'] ? 'event sourced' : 'state stored'];
        }

        return $facts;
    }

    /**
     * @param list<array{service: string, label: string, kind: string, runs: ?string}> $incoming
     * @param list<array{service: string, label: string, kind: string, runs: ?string}> $outgoing
     */
    private static function sentenceOf(ServiceFlow $flow, FlowNode $node, array $incoming, array $outgoing): string
    {
        $handlers = array_values(array_filter($outgoing, static fn (array $one): bool => $one['runs'] !== null));
        $runs = static fn (array $one): string => str_starts_with((string) $one['runs'], 'synchronously') && $one['service'] !== $flow->name
            ? 'runs in ' . $one['service'] . ' as soon as the message arrives over the Distributed Bus'
            : 'runs ' . $one['runs'];
        $senders = array_values(array_unique(array_map(static fn (array $one): string => $one['service'], array_filter($incoming, static fn (array $one): bool => $one['service'] !== $flow->name))));
        $received = $node->kind->isMessage() && $senders !== [] ? sprintf(' %s receives it from %s over the Distributed Bus.', ucfirst($flow->name), implode(' and ', $senders)) : '';
        $named = static fn (array $one): string => sprintf('%s in %s, which %s', $one['label'], $one['service'], $runs($one));
        $kind = strtolower((new DiagramNode('', null, $node->kind->value, ''))->kindLabel());

        return match (true) {
            $node->kind === FlowNodeKind::EVENT => ($handlers === []
                ? sprintf('An event of %s that no handler in the services Pulse knows subscribes to.', $flow->name)
                : sprintf('An event of %s with %d %s: %s.', $flow->name, count($handlers), count($handlers) === 1 ? 'subscriber' : 'subscribers', implode('; ', array_map($named, $handlers)))) . $received,
            $node->kind->isMessage() => ($handlers === []
                ? sprintf('A %s that %s sends and no handler in the services Pulse knows takes.', $kind, $flow->name)
                : sprintf('A %s handled by %s.', $kind, implode('; ', array_map($named, $handlers)))) . $received,
            $node->kind->isHandler() => sprintf('A %s of %s that runs %s.', $kind, $flow->name, self::runsOf($flow, $node)),
            $node->kind === FlowNodeKind::CHANNEL => sprintf('A channel of %s where messages wait for a consumer: %d %s from it.', $flow->name, count($handlers), count($handlers) === 1 ? 'handler runs' : 'handlers run'),
            default => sprintf('%s of %s.', ucfirst($kind), $flow->name),
        };
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     * @return array{service: string, flowType: string, runs: int, runsWithErrors: int, p50Ms: ?float, p95Ms: ?float, maxMs: ?float}|null
     */
    private static function flowTypeOf(array $snapshots, string $service, FlowNode $message): ?array
    {
        $names = [ServiceFlow::routingKeyOf($message), $message->label];
        foreach (FlowTypeOverview::of($snapshots) as $traced) {
            if ($traced['name'] !== $service) {
                continue;
            }
            foreach ($traced['flowTypes'] as $flowType) {
                if (in_array((string) ($flowType['flowType'] ?? ''), $names, true)) {
                    $number = static fn (string $key): ?float => is_numeric($flowType[$key] ?? null) ? (float) $flowType[$key] : null;

                    return ['service' => $traced['service'], 'flowType' => (string) $flowType['flowType'], 'runs' => (int) ($flowType['runs'] ?? 0), 'runsWithErrors' => (int) ($flowType['runsWithErrors'] ?? 0), 'p50Ms' => $number('p50Ms'), 'p95Ms' => $number('p95Ms'), 'maxMs' => $number('maxMs')];
                }
            }
        }

        return null;
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     * @return array{messageName: string, count: int}|null
     */
    private static function deadLettersOf(array $snapshots, string $service, FlowNode $message): ?array
    {
        $counts = [];
        foreach (DeadLetterOverview::of($snapshots, new DeadLetterFilter('', '', '', '', '', '', '')) as $stored) {
            if ($stored['name'] !== $service) {
                continue;
            }
            foreach ($stored['groups'] as $group) {
                $name = (string) ($group['messageName'] ?? '');
                $counts[$name] = ($counts[$name] ?? 0) + (int) ($group['count'] ?? 0);
            }
        }
        foreach ([ServiceFlow::routingKeyOf($message), $message->label] as $name) {
            if (($counts[$name] ?? 0) > 0) {
                return ['messageName' => $name, 'count' => $counts[$name]];
            }
        }

        return null;
    }
}
