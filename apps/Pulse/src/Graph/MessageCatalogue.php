<?php

declare(strict_types=1);

namespace App\Graph;

use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;

final class MessageCatalogue
{
    /**
     * @param list<ServiceFlow> $flows
     * @return list<array{service: string, kind: string, name: string, routingKey: string, handlers: list<array{label: string, endpointId: ?string, channel: ?string}>, emittedBy: list<string>}>
     */
    public static function search(array $flows, string $query): array
    {
        $entries = [];
        foreach ($flows as $flow) {
            $graph = $flow->graph;
            foreach ($graph->nodesOfKind(FlowNodeKind::COMMAND, FlowNodeKind::EVENT, FlowNodeKind::QUERY) as $message) {
                $handlers = [];
                foreach ($graph->edgesOfKind(FlowEdgeKind::ROUTED_TO, from: $message->id) as $routed) {
                    $handler = $graph->node($routed->to);
                    $channels = $graph->edgesOfKind(FlowEdgeKind::ASYNCHRONOUS, to: $handler->id);
                    $handlers[] = [
                        'label' => $handler->label,
                        'endpointId' => is_string($handler->attributes['endpointId'] ?? null) ? $handler->attributes['endpointId'] : null,
                        'channel' => $channels === [] ? null : $graph->node($channels[0]->from)->label,
                    ];
                }
                $entry = [
                    'service' => $flow->name,
                    'kind' => $message->kind->value,
                    'name' => $message->label,
                    'routingKey' => ServiceFlow::routingKeyOf($message),
                    'handlers' => $handlers,
                    'emittedBy' => array_map(fn ($edge) => $graph->node($edge->from)->label, $graph->edgesOfKind(FlowEdgeKind::EMITS, to: $message->id)),
                ];
                if (self::matches($entry, $query)) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @param array{service: string, name: string, routingKey: string, handlers: list<array{label: string, endpointId: ?string, channel: ?string}>, emittedBy: list<string>} $entry
     */
    private static function matches(array $entry, string $query): bool
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return true;
        }
        $haystacks = [$entry['service'], $entry['name'], $entry['routingKey'], ...$entry['emittedBy']];
        foreach ($entry['handlers'] as $handler) {
            array_push($haystacks, $handler['label'], (string) $handler['endpointId'], (string) $handler['channel']);
        }
        foreach ($haystacks as $haystack) {
            if (str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }
}
