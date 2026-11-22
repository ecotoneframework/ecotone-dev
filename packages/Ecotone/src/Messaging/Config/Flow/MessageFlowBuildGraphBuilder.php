<?php

/*
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Modelling\Config\MessageBusChannel;

final class MessageFlowBuildGraphBuilder
{
    public function __construct(
        private MessageFlowGraphBuilder $applicationFlow,
        private MessageFlowBuildWiring $buildWiring,
    ) {
    }

    public function build(): MessageFlowGraph
    {
        $application = $this->applicationFlow->build();
        $nodes = $application->nodes();
        foreach ($this->buildWiring->nodes as $node) {
            if (! $application->hasNode($node['id'])) {
                $nodes[] = FlowNode::fromArray($node);
            }
        }
        $buildEdges = array_map(fn (array $edge): FlowEdge => FlowEdge::fromArray($edge), $this->buildWiring->edges);

        return MessageFlowGraph::create($nodes, [...$application->edges(), ...$buildEdges, ...$this->busRoutes($application, $buildEdges)]);
    }

    /**
     * @param list<FlowEdge> $buildEdges
     * @return list<FlowEdge>
     */
    private function busRoutes(MessageFlowGraph $application, array $buildEdges): array
    {
        $routingKeys = [];
        foreach ($application->edgesOfKind(FlowEdgeKind::ROUTED_TO) as $routed) {
            $message = $application->node($routed->from);
            $entryChannel = $this->buildWiring->entryChannels[$routed->to] ?? null;
            $busChannel = match ($message->kind) {
                FlowNodeKind::COMMAND => MessageBusChannel::COMMAND_CHANNEL_NAME_BY_NAME,
                FlowNodeKind::QUERY => MessageBusChannel::QUERY_CHANNEL_NAME_BY_NAME,
                FlowNodeKind::EVENT => MessageBusChannel::EVENT_CHANNEL_NAME_BY_NAME,
                default => null,
            };
            if ($entryChannel === null || $busChannel === null) {
                continue;
            }
            foreach ($buildEdges as $input) {
                if ($input->kind === FlowEdgeKind::INPUT && $input->from === 'channel:' . $busChannel) {
                    $routingKeys[$input->to][$entryChannel][] = (string) ($message->attributes['routingKey'] ?? $message->label);
                }
            }
        }
        $routes = [];
        foreach ($routingKeys as $busRouting => $entryChannels) {
            foreach ($entryChannels as $entryChannel => $keys) {
                sort($keys, SORT_STRING);
                $routes[] = new FlowEdge(from: $busRouting, to: $entryChannel, kind: FlowEdgeKind::ROUTES_TO, attributes: ['routingKeys' => array_values(array_unique($keys)), 'framework' => true]);
            }
        }

        return $routes;
    }
}
