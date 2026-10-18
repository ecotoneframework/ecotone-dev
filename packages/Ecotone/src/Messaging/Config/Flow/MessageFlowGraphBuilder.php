<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Gateway\DistributedBus;
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
    private const DISTRIBUTED_COMMAND_CALLS = ['sendCommand', 'convertAndSendCommand'];
    private const DISTRIBUTED_EVENT_CALLS = ['publishEvent', 'convertAndPublishEvent'];

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
        $handlers = $this->declared->nodesOfKind(FlowNodeKind::COMMAND_HANDLER, FlowNodeKind::EVENT_HANDLER, FlowNodeKind::QUERY_HANDLER, FlowNodeKind::INTERNAL_HANDLER, FlowNodeKind::ROUTER);
        $targets = [];
        foreach ($handlers as $handler) {
            $targets[$handler->id] = [(string) $handler->attributes['class'], (string) $handler->attributes['method']];
        }
        $facts = $this->scanner->scan($targets);

        $inferred = new InferredFlow($this->declared);
        foreach ($handlers as $handler) {
            $this->inferEmissions($inferred, $handler, $facts[$handler->id]);
            $this->inferCalls($inferred, $handler, $facts[$handler->id]);
            $this->inferDistribution($inferred, $handler, $facts[$handler->id]);
            if ($handler->kind === FlowNodeKind::ROUTER) {
                $this->inferRoutes($inferred, $handler, $facts[$handler->id]);
            }
        }

        return $inferred->graph();
    }

    private function inferEmissions(InferredFlow $flow, FlowNode $handler, MethodSourceFacts $facts): void
    {
        foreach ($facts->instantiations as $instantiation) {
            $messageId = 'message:' . $instantiation['class'];
            $call = $instantiation['enclosingCall'];
            if (! $flow->hasNode($messageId)) {
                if (in_array($call, self::EVENT_CALLS, true) || ($call === null && $instantiation['returned'] && $this->implicitEmissionOf($flow, $handler) === 'return')) {
                    $flow->addNode($this->unhandledMessage($instantiation['class'], FlowNodeKind::EVENT));
                } elseif (in_array($call, self::SEND_CALLS, true)) {
                    $receiverType = $this->collaborators[$handler->id][$instantiation['receiver'] ?? ''] ?? '';
                    $flow->addNode($this->unhandledMessage($instantiation['class'], is_a($receiverType, QueryBus::class, true) ? FlowNodeKind::QUERY : FlowNodeKind::COMMAND));
                } else {
                    continue;
                }
            }
            $flow->addEdge(new FlowEdge($handler->id, $messageId, FlowEdgeKind::EMITS, ['inferred' => true, 'via' => $call ?? $this->implicitEmissionOf($flow, $handler)]));
        }
        foreach ($facts->calls as $call) {
            if (! in_array($call['method'], self::ROUTING_KEY_CALLS, true) || ! isset($call['literalArguments'][0])) {
                continue;
            }
            $routingKey = $call['literalArguments'][0];
            $messageId = 'message:' . $routingKey;
            if (! $flow->hasNode($messageId)) {
                $receiverType = $this->collaborators[$handler->id][$call['receiver'] ?? ''] ?? null;
                $kind = match (true) {
                    $call['method'] === 'publishWithRouting' => FlowNodeKind::EVENT,
                    $receiverType === QueryBus::class => FlowNodeKind::QUERY,
                    default => FlowNodeKind::COMMAND,
                };
                $flow->addNode($this->unhandledMessage($routingKey, $kind));
            }
            $flow->addEdge(new FlowEdge($handler->id, $messageId, FlowEdgeKind::EMITS, ['inferred' => true, 'via' => $call['method']]));
        }
    }

    private function inferCalls(InferredFlow $flow, FlowNode $handler, MethodSourceFacts $facts): void
    {
        foreach ($facts->calls as $call) {
            $receiverType = $this->collaborators[$handler->id][$call['receiver'] ?? ''] ?? null;
            if ($receiverType === null) {
                continue;
            }
            $gatewayId = 'gateway:' . $receiverType . '::' . $call['method'];
            if ($flow->hasNode($gatewayId)) {
                $flow->addEdge(new FlowEdge($handler->id, $gatewayId, FlowEdgeKind::CALLS, ['inferred' => true]));
            }
        }
    }

    private function inferDistribution(InferredFlow $flow, FlowNode $handler, MethodSourceFacts $facts): void
    {
        foreach ($facts->calls as $call) {
            $receiverType = $this->collaborators[$handler->id][$call['receiver'] ?? ''] ?? null;
            if ($receiverType === null || ! is_a($receiverType, DistributedBus::class, true)) {
                continue;
            }
            $busId = 'distributed:' . $receiverType;
            if (! $flow->hasNode($busId)) {
                $flow->addNode(new FlowNode($busId, FlowNodeKind::DISTRIBUTED_BUS, MessageFlowDiscovery::distributedBusLabel($receiverType), ['reference' => $receiverType]));
            }
            $arguments = $call['literalArguments'];
            if (in_array($call['method'], self::DISTRIBUTED_COMMAND_CALLS, true) && isset($arguments[1])) {
                $this->addDistributedMessage($flow, $handler->id, $busId, $arguments[1], FlowNodeKind::COMMAND, $call['method'], ['targetService' => $arguments[0]]);
            } elseif (in_array($call['method'], self::DISTRIBUTED_EVENT_CALLS, true) && isset($arguments[0])) {
                $this->addDistributedMessage($flow, $handler->id, $busId, $arguments[0], FlowNodeKind::EVENT, $call['method'], []);
            } elseif ($call['method'] === 'sendMessage' && isset($arguments[1])) {
                $flow->addEdge(new FlowEdge($handler->id, $busId, FlowEdgeKind::SENDS_TO, ['inferred' => true, 'via' => 'sendMessage', 'targetService' => $arguments[0], 'targetChannel' => $arguments[1]]));
            }
        }
    }

    /**
     * @param array<string, string> $target
     */
    private function addDistributedMessage(InferredFlow $flow, string $handlerId, string $busId, string $routingKey, FlowNodeKind $kind, string $via, array $target): void
    {
        $messageId = 'message:' . $routingKey;
        if (! $flow->hasNode($messageId)) {
            $flow->addNode($this->unhandledMessage($routingKey, $kind));
        }
        $flow->addNode($flow->node($messageId)->withAttributes(['distributed' => true] + $target));
        $flow->addEdge(new FlowEdge($handlerId, $messageId, FlowEdgeKind::EMITS, ['inferred' => true, 'via' => $via] + $target));
        $flow->addEdge(new FlowEdge($messageId, $busId, FlowEdgeKind::SENDS_TO, ['inferred' => true] + $target));
    }

    private function inferRoutes(InferredFlow $flow, FlowNode $router, MethodSourceFacts $facts): void
    {
        foreach ($facts->stringLiterals as $literal) {
            $channelId = 'channel:' . $literal;
            if ($flow->hasNode($channelId)) {
                $flow->addEdge(new FlowEdge($router->id, $channelId, FlowEdgeKind::ROUTES_TO, ['inferred' => true]));
            }
        }
    }

    private function implicitEmissionOf(InferredFlow $flow, FlowNode $handler): string
    {
        if (($handler->attributes['returnsEvents'] ?? false) === true) {
            return 'return';
        }
        $ownerId = $handler->attributes['owner'] ?? null;
        if (is_string($ownerId) && $flow->hasNode($ownerId) && ($flow->node($ownerId)->attributes['eventSourced'] ?? false) === true) {
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
