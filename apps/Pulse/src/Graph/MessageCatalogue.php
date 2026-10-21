<?php

declare(strict_types=1);

namespace App\Graph;

use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;

final class MessageCatalogue
{
    /**
     * @param list<ServiceFlow> $flows
     * @return list<array{service: string, kind: string, aggregate: ?string, entries: list<array{service: string, kind: string, name: string, routingKey: string, aggregate: ?string, handlers: list<array{label: string, endpointId: ?string, channel: ?string, owner: ?string}>, subscribers: list<array{service: string, label: ?string, endpointId: ?string, channel: ?string, owner: ?string}>, emittedBy: list<string>}>}>
     */
    public static function groups(array $flows, string $query, string $service = ''): array
    {
        $links = DistributedLinks::between($flows);
        $groups = [];
        foreach ($flows as $flow) {
            if ($service !== '' && $flow->name !== $service) {
                continue;
            }
            $byAggregate = [];
            foreach (self::entriesOf($flow, $flows, $links) as $entry) {
                if (self::matches($entry, $query)) {
                    $byAggregate[$entry['aggregate'] ?? ''][] = $entry;
                }
            }
            ksort($byAggregate);
            $ungrouped = $byAggregate[''] ?? [];
            unset($byAggregate['']);
            foreach ($byAggregate as $aggregate => $entries) {
                $groups[] = ['service' => $flow->name, 'kind' => 'aggregate', 'aggregate' => (string) $aggregate, 'entries' => $entries];
            }
            if ($ungrouped !== []) {
                $groups[] = ['service' => $flow->name, 'kind' => 'services', 'aggregate' => null, 'entries' => $ungrouped];
            }
        }

        return $groups;
    }

    /**
     * @param list<ServiceFlow> $flows
     * @return list<string>
     */
    public static function services(array $flows): array
    {
        $names = array_map(fn (ServiceFlow $flow) => $flow->name, $flows);
        sort($names);

        return $names;
    }

    /**
     * @param list<ServiceFlow> $flows
     * @param list<DistributedLink> $links
     * @return list<array<string, mixed>>
     */
    private static function entriesOf(ServiceFlow $flow, array $flows, array $links): array
    {
        $graph = $flow->graph;
        $entries = [];
        foreach ($graph->nodesOfKind(FlowNodeKind::COMMAND, FlowNodeKind::EVENT, FlowNodeKind::QUERY) as $message) {
            $handlers = [];
            $owners = [];
            foreach ($graph->edgesOfKind(FlowEdgeKind::ROUTED_TO, from: $message->id) as $routed) {
                $handler = $graph->node($routed->to);
                $channels = $graph->edgesOfKind(FlowEdgeKind::ASYNCHRONOUS, to: $handler->id);
                $owner = self::ownerOf($flow, $handler);
                $handlers[] = [
                    'label' => $handler->label,
                    'endpointId' => is_string($handler->attributes['endpointId'] ?? null) ? $handler->attributes['endpointId'] : null,
                    'channel' => $channels === [] ? null : $graph->node($channels[0]->from)->label,
                    'owner' => $owner === null || $owner->kind === FlowNodeKind::AGGREGATE ? null : $owner->kind->value . ' ' . $owner->label,
                ];
                if ($owner !== null && $owner->kind === FlowNodeKind::AGGREGATE && $message->kind !== FlowNodeKind::EVENT) {
                    $owners[$owner->label] = true;
                }
            }
            $emitters = $graph->edgesOfKind(FlowEdgeKind::EMITS, to: $message->id);
            if ($message->kind === FlowNodeKind::EVENT) {
                foreach ($emitters as $emitter) {
                    $owner = self::ownerOf($flow, $graph->node($emitter->from));
                    if ($owner !== null && $owner->kind === FlowNodeKind::AGGREGATE) {
                        $owners[$owner->label] = true;
                    }
                }
                foreach ($graph->edgesOfKind(FlowEdgeKind::APPLIES, to: $message->id) as $applied) {
                    $owner = $graph->node($applied->from);
                    if ($owner->kind === FlowNodeKind::AGGREGATE) {
                        $owners[$owner->label] = true;
                    }
                }
            }
            $subscribers = $message->kind === FlowNodeKind::EVENT ? self::subscribersOf($flow, $message, $handlers, $flows, $links) : [];
            foreach ($owners === [] ? [null] : array_keys($owners) as $aggregate) {
                $entries[] = [
                    'service' => $flow->name,
                    'kind' => $message->kind->value,
                    'name' => $message->label,
                    'routingKey' => ServiceFlow::routingKeyOf($message),
                    'aggregate' => $aggregate === null ? null : (string) $aggregate,
                    'handlers' => $handlers,
                    'subscribers' => $subscribers,
                    'emittedBy' => array_map(fn ($edge) => $graph->node($edge->from)->label, $emitters),
                ];
            }
        }

        return $entries;
    }

    private static function ownerOf(ServiceFlow $flow, FlowNode $handler): ?FlowNode
    {
        $owner = $handler->attributes['owner'] ?? null;

        return is_string($owner) && $flow->graph->hasNode($owner) ? $flow->graph->node($owner) : null;
    }

    /**
     * @param list<array{label: string, endpointId: ?string, channel: ?string, owner: ?string}> $localHandlers
     * @param list<ServiceFlow> $flows
     * @param list<DistributedLink> $links
     * @return list<array{service: string, label: ?string, endpointId: ?string, channel: ?string, owner: ?string}>
     */
    private static function subscribersOf(ServiceFlow $flow, FlowNode $event, array $localHandlers, array $flows, array $links): array
    {
        $subscribers = array_map(fn (array $handler) => ['service' => $flow->name, ...$handler], $localHandlers);
        foreach ($links as $link) {
            if ($link->kind !== 'event' || $link->fromService !== $flow->name || $link->fromNodeId !== $event->id) {
                continue;
            }
            foreach ($flows as $receiver) {
                if ($receiver->name !== $link->toService) {
                    continue;
                }
                $handlers = $receiver->graph->edgesOfKind(FlowEdgeKind::ROUTED_TO, from: $link->toNodeId);
                if ($handlers === []) {
                    $subscribers[] = ['service' => $receiver->name, 'label' => null, 'endpointId' => null, 'channel' => null, 'owner' => null];
                }
                foreach ($handlers as $routed) {
                    $handler = $receiver->graph->node($routed->to);
                    $channels = $receiver->graph->edgesOfKind(FlowEdgeKind::ASYNCHRONOUS, to: $handler->id);
                    $owner = self::ownerOf($receiver, $handler);
                    $subscribers[] = [
                        'service' => $receiver->name,
                        'label' => $handler->label,
                        'endpointId' => is_string($handler->attributes['endpointId'] ?? null) ? $handler->attributes['endpointId'] : null,
                        'channel' => $channels === [] ? null : $receiver->graph->node($channels[0]->from)->label,
                        'owner' => $owner === null || $owner->kind === FlowNodeKind::AGGREGATE ? null : $owner->kind->value . ' ' . $owner->label,
                    ];
                }
            }
        }

        return $subscribers;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function matches(array $entry, string $query): bool
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return true;
        }
        $haystacks = [$entry['service'], $entry['name'], $entry['routingKey'], (string) $entry['aggregate'], ...$entry['emittedBy']];
        foreach ([...$entry['handlers'], ...$entry['subscribers']] as $handler) {
            array_push($haystacks, (string) $handler['label'], (string) $handler['endpointId'], (string) $handler['channel'], (string) $handler['owner'], (string) ($handler['service'] ?? ''));
        }
        foreach ($haystacks as $haystack) {
            if (str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }
}
