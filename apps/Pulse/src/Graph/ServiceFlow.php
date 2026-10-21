<?php

declare(strict_types=1);

namespace App\Graph;

use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Throwable;

final readonly class ServiceFlow
{
    /**
     * @param list<array<string, mixed>> $serviceMaps
     */
    public function __construct(
        public string $name,
        public string $channel,
        public MessageFlowGraph $graph,
        public array $serviceMaps,
        public ServiceStatus $status,
        public ?int $ageSeconds,
    ) {
    }

    public static function fromSnapshot(ServiceSnapshot $snapshot): ?self
    {
        if (! is_array($snapshot->snapshot['flowGraph'] ?? null)) {
            return null;
        }

        try {
            $graph = MessageFlowGraph::fromArray($snapshot->snapshot['flowGraph']);
        } catch (Throwable) {
            return null;
        }

        $serviceMaps = $snapshot->snapshot['topology']['serviceMaps'] ?? [];

        return new self($snapshot->service, $snapshot->channel, $graph, is_array($serviceMaps) ? array_values($serviceMaps) : [], $snapshot->status, $snapshot->ageSeconds);
    }

    public function isStale(): bool
    {
        return $this->status !== ServiceStatus::Connected;
    }

    public static function routingKeyOf(FlowNode $message): string
    {
        $routingKey = $message->attributes['routingKey'] ?? null;

        return is_string($routingKey) ? $routingKey : $message->label;
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
     * @return list<array{handler: FlowNode, targetService: string, targetChannel: string}>
     */
    public function sentMessages(): array
    {
        $sent = [];
        foreach ($this->graph->edgesOfKind(FlowEdgeKind::SENDS_TO) as $edge) {
            $service = $edge->attributes['targetService'] ?? null;
            $channel = $edge->attributes['targetChannel'] ?? null;
            if (($edge->attributes['via'] ?? null) === 'sendMessage' && is_string($service) && is_string($channel) && $this->graph->node($edge->to)->kind === FlowNodeKind::DISTRIBUTED_BUS) {
                $sent[] = ['handler' => $this->graph->node($edge->from), 'targetService' => $service, 'targetChannel' => $channel];
            }
        }

        return $sent;
    }

    /**
     * @return list<array{handler: FlowNode, channel: string, replies: bool}>
     */
    public function distributedChannelHandlers(): array
    {
        $handlers = [];
        foreach ($this->graph->edgesOfKind(FlowEdgeKind::DISTRIBUTES) as $distributes) {
            $channel = $this->graph->node($distributes->to);
            if ($channel->kind !== FlowNodeKind::CHANNEL) {
                continue;
            }
            foreach ($this->graph->edgesOfKind(FlowEdgeKind::INPUT, from: $channel->id) as $input) {
                $handler = $this->graph->node($input->to);
                $replies = array_filter(
                    $this->graph->edgesOfKind(FlowEdgeKind::SENDS_TO, from: $handler->id),
                    fn ($edge) => ($edge->attributes['via'] ?? null) === 'replyRoutingKey'
                );
                $handlers[] = ['handler' => $handler, 'channel' => $channel->label, 'replies' => $replies !== []];
            }
        }

        return $handlers;
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

    public function publishesToChannel(string $routingKey, string $channel): bool
    {
        foreach ($this->eventSubscriptions() as $subscription) {
            $included = $subscription['includePublishingServices'] === [] || in_array($this->name, $subscription['includePublishingServices'], true);
            $excluded = in_array($this->name, $subscription['excludePublishingServices'], true);
            if ($subscription['channel'] === $channel && $included && ! $excluded && self::anyKeyMatches($subscription['subscriptionKeys'], $routingKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $patterns
     */
    private static function anyKeyMatches(array $patterns, string $routingKey): bool
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
