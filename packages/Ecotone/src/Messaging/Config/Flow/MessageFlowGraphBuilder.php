<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;

final class MessageFlowGraphBuilder
{
    private const EVENT_CALLS = ['recordThat', 'publish', 'publishWithRouting'];
    private const SEND_CALLS = ['send', 'sendWithRouting'];
    private const ROUTING_KEY_CALLS = ['publishWithRouting', 'sendWithRouting'];

    /**
     * @param array<string, array<string, string>> $collaborators handler node id => (receiver => class)
     */
    public function __construct(
        private MessageFlowGraph $declared,
        private array $collaborators,
        private HandlerSourceScanner $scanner,
    ) {
    }

    public function build(): MessageFlowGraph
    {
        $graph = $this->declared;
        foreach ($this->declared->nodesOfKind(FlowNodeKind::COMMAND_HANDLER, FlowNodeKind::EVENT_HANDLER, FlowNodeKind::QUERY_HANDLER, FlowNodeKind::INTERNAL_HANDLER, FlowNodeKind::ROUTER) as $handler) {
            $facts = $this->scanner->scan((string) $handler->attributes['class'], (string) $handler->attributes['method']);
            $graph = $this->inferEmissions($graph, $handler, $facts);
            $graph = $this->inferCalls($graph, $handler, $facts);
            if ($handler->kind === FlowNodeKind::ROUTER) {
                $graph = $this->inferRoutes($graph, $handler, $facts);
            }
        }

        return $graph;
    }

    private function inferEmissions(MessageFlowGraph $graph, FlowNode $handler, MethodSourceFacts $facts): MessageFlowGraph
    {
        foreach ($facts->instantiations as $instantiation) {
            $messageId = 'message:' . $instantiation['class'];
            $call = $instantiation['enclosingCall'];
            if (! $graph->hasNode($messageId)) {
                if (in_array($call, self::EVENT_CALLS, true)) {
                    $graph = $graph->withNode($this->unhandledMessage($instantiation['class'], FlowNodeKind::EVENT));
                } elseif (in_array($call, self::SEND_CALLS, true)) {
                    $graph = $graph->withNode($this->unhandledMessage($instantiation['class'], FlowNodeKind::COMMAND));
                } else {
                    continue;
                }
            }
            $graph = $this->withEmission($graph, $handler->id, $messageId, $call ?? $this->implicitEmissionOf($graph, $handler));
        }
        foreach ($facts->calls as $call) {
            if (! in_array($call['method'], self::ROUTING_KEY_CALLS, true) || $call['firstArgumentLiteral'] === null) {
                continue;
            }
            $routingKey = $call['firstArgumentLiteral'];
            $messageId = 'message:' . $routingKey;
            if (! $graph->hasNode($messageId)) {
                $receiverType = $this->collaborators[$handler->id][$call['receiver'] ?? ''] ?? null;
                $kind = match (true) {
                    $call['method'] === 'publishWithRouting' => FlowNodeKind::EVENT,
                    $receiverType === QueryBus::class => FlowNodeKind::QUERY,
                    default => FlowNodeKind::COMMAND,
                };
                $graph = $graph->withNode($this->unhandledMessage($routingKey, $kind));
            }
            $graph = $this->withEmission($graph, $handler->id, $messageId, $call['method']);
        }

        return $graph;
    }

    private function inferCalls(MessageFlowGraph $graph, FlowNode $handler, MethodSourceFacts $facts): MessageFlowGraph
    {
        foreach ($facts->calls as $call) {
            $receiverType = $this->collaborators[$handler->id][$call['receiver'] ?? ''] ?? null;
            if ($receiverType === null) {
                continue;
            }
            $gatewayId = 'gateway:' . $receiverType . '::' . $call['method'];
            if ($graph->hasNode($gatewayId) && $graph->edgesOfKind(FlowEdgeKind::CALLS, from: $handler->id, to: $gatewayId) === []) {
                $graph = $graph->withEdge(new FlowEdge($handler->id, $gatewayId, FlowEdgeKind::CALLS, ['inferred' => true]));
            }
        }

        return $graph;
    }

    private function inferRoutes(MessageFlowGraph $graph, FlowNode $router, MethodSourceFacts $facts): MessageFlowGraph
    {
        foreach ($facts->stringLiterals as $literal) {
            $channelId = 'channel:' . $literal;
            if ($graph->hasNode($channelId) && $graph->edgesOfKind(FlowEdgeKind::ROUTES_TO, from: $router->id, to: $channelId) === []) {
                $graph = $graph->withEdge(new FlowEdge($router->id, $channelId, FlowEdgeKind::ROUTES_TO, ['inferred' => true]));
            }
        }

        return $graph;
    }

    private function withEmission(MessageFlowGraph $graph, string $handlerId, string $messageId, string $via): MessageFlowGraph
    {
        if ($graph->edgesOfKind(FlowEdgeKind::EMITS, from: $handlerId, to: $messageId) !== []) {
            return $graph;
        }

        return $graph->withEdge(new FlowEdge($handlerId, $messageId, FlowEdgeKind::EMITS, ['inferred' => true, 'via' => $via]));
    }

    private function implicitEmissionOf(MessageFlowGraph $graph, FlowNode $handler): string
    {
        $ownerId = $handler->attributes['owner'] ?? null;
        if (is_string($ownerId) && $graph->hasNode($ownerId) && ($graph->node($ownerId)->attributes['eventSourced'] ?? false) === true) {
            return 'return';
        }

        return 'new';
    }

    private function unhandledMessage(string $routingKey, FlowNodeKind $kind): FlowNode
    {
        $isClass = class_exists($routingKey) || interface_exists($routingKey);
        $segments = explode('\\', $routingKey);

        return new FlowNode('message:' . $routingKey, $kind, $isClass ? end($segments) : $routingKey, [
            'routingKey' => $routingKey,
            'class' => $isClass ? $routingKey : null,
            'handled' => false,
        ]);
    }
}
