<?php

declare(strict_types=1);

namespace App\Graph;

use App\Snapshot\ServiceSnapshot;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Throwable;

final readonly class ServiceFlow
{
    private const MESSAGE_PREFIX = 'message:';

    /**
     * @param list<array<string, mixed>> $serviceMaps
     */
    public function __construct(
        public string $pulseName,
        public string $name,
        public MessageFlowGraph $graph,
        public array $serviceMaps,
    ) {
    }

    public static function fromSnapshot(ServiceSnapshot $snapshot): ?self
    {
        if (! $snapshot->hasSnapshot() || ! is_array($snapshot->snapshot['flowGraph'] ?? null)) {
            return null;
        }

        try {
            $graph = MessageFlowGraph::fromArray($snapshot->snapshot['flowGraph']);
        } catch (Throwable) {
            return null;
        }

        $name = $snapshot->snapshot['serviceName'] ?? null;
        $serviceMaps = $snapshot->snapshot['topology']['serviceMaps'] ?? [];

        return new self($snapshot->service, is_string($name) ? $name : $snapshot->service, $graph, is_array($serviceMaps) ? array_values($serviceMaps) : []);
    }

    public static function routingKeyOf(FlowNode $message): string
    {
        return str_starts_with($message->id, self::MESSAGE_PREFIX) ? substr($message->id, strlen(self::MESSAGE_PREFIX)) : $message->label;
    }

    /**
     * @return list<array{node: FlowNode, routingKey: string, targetService: string}>
     */
    public function sentCommands(): array
    {
        $sent = [];
        foreach ($this->graph->nodesOfKind(FlowNodeKind::COMMAND) as $node) {
            $target = $node->attributes['targetService'] ?? null;
            if (is_string($target) && $this->sendsToDistributedBus($node)) {
                $sent[] = ['node' => $node, 'routingKey' => self::routingKeyOf($node), 'targetService' => $target];
            }
        }

        return $sent;
    }

    /**
     * @return list<string>
     */
    public function targetServicesSentTo(): array
    {
        $targets = array_map(fn (array $sent) => $sent['targetService'], $this->sentCommands());
        foreach ($this->graph->edgesOfKind(FlowEdgeKind::SENDS_TO) as $edge) {
            $target = $edge->attributes['targetService'] ?? null;
            if (is_string($target) && $this->graph->node($edge->to)->kind === FlowNodeKind::DISTRIBUTED_BUS) {
                $targets[] = $target;
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * @return list<array{node: FlowNode, routingKey: string}>
     */
    public function publishedEvents(): array
    {
        $published = [];
        foreach ($this->graph->nodesOfKind(FlowNodeKind::EVENT) as $node) {
            if (($node->attributes['targetService'] ?? null) === null && $this->sendsToDistributedBus($node)) {
                $published[] = ['node' => $node, 'routingKey' => self::routingKeyOf($node)];
            }
        }

        return $published;
    }

    /**
     * @return list<array{node: FlowNode, routingKey: string}>
     */
    public function distributedReceivers(): array
    {
        $receivers = [];
        foreach ($this->graph->nodesOfKind(FlowNodeKind::COMMAND, FlowNodeKind::EVENT) as $node) {
            foreach ($this->graph->edgesOfKind(FlowEdgeKind::DISTRIBUTES, to: $node->id) as $edge) {
                if ($this->graph->node($edge->from)->kind === FlowNodeKind::DISTRIBUTED_BUS) {
                    $receivers[] = ['node' => $node, 'routingKey' => self::routingKeyOf($node)];
                    break;
                }
            }
        }

        return $receivers;
    }

    /**
     * @return array<string, string>
     */
    public function commandMappings(): array
    {
        $mappings = [];
        foreach ($this->serviceMaps as $serviceMap) {
            $mappings = [...$mappings, ...($serviceMap['commandMapping'] ?? [])];
        }

        return $mappings;
    }

    /**
     * @return list<array{channel: string, subscriptionKeys: list<string>, excludePublishingServices: list<string>, includePublishingServices: list<string>}>
     */
    public function eventSubscriptions(): array
    {
        $subscriptions = [];
        foreach ($this->serviceMaps as $serviceMap) {
            foreach ($serviceMap['eventSubscriptions'] ?? [] as $subscription) {
                $subscriptions[] = [
                    'channel' => $subscription['channel'],
                    'subscriptionKeys' => $subscription['subscriptionKeys'],
                    'excludePublishingServices' => $subscription['excludePublishingServices'] ?? [],
                    'includePublishingServices' => $subscription['includePublishingServices'] ?? [],
                ];
            }
        }

        return $subscriptions;
    }

    public function publishesEventTo(string $routingKey): bool
    {
        foreach ($this->eventSubscriptions() as $subscription) {
            $included = $subscription['includePublishingServices'] === [] || in_array($this->name, $subscription['includePublishingServices'], true);
            $excluded = in_array($this->name, $subscription['excludePublishingServices'], true);
            if ($included && ! $excluded && $this->anyKeyMatches($subscription['subscriptionKeys'], $routingKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $patterns
     */
    private function anyKeyMatches(array $patterns, string $routingKey): bool
    {
        foreach ($patterns as $pattern) {
            if (RoutingKeyPattern::matches($pattern, $routingKey)) {
                return true;
            }
        }

        return false;
    }

    private function sendsToDistributedBus(FlowNode $message): bool
    {
        foreach ($this->graph->edgesOfKind(FlowEdgeKind::SENDS_TO, from: $message->id) as $edge) {
            if ($this->graph->node($edge->to)->kind === FlowNodeKind::DISTRIBUTED_BUS) {
                return true;
            }
        }

        return false;
    }
}
