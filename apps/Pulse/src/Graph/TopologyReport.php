<?php

declare(strict_types=1);

namespace App\Graph;

final readonly class TopologyReport
{
    /**
     * @param list<array{from: string, to: string, channel: string, stale: bool}> $commandRoutes
     * @param list<array{service: string, channel: string, keys: list<string>, stale: bool}> $eventSubscriptions
     * @param list<Mismatch> $mismatches
     * @param list<string> $incompleteBecause
     */
    private function __construct(public array $commandRoutes, public array $eventSubscriptions, public array $mismatches, public array $incompleteBecause)
    {
    }

    /**
     * @param list<string> $configuredServices
     */
    public static function of(ServiceFlows $serviceFlows, array $configuredServices, string $pulseServiceName): self
    {
        $flows = $serviceFlows->flows;
        $routes = [];
        $subscriptions = [];
        $mismatches = [];
        $answering = array_map(fn (ServiceFlow $flow) => $flow->name, array_filter($flows, fn (ServiceFlow $flow) => ! $flow->isStale()));
        $publishedKeys = [];
        foreach ($flows as $flow) {
            foreach ($flow->publishedEvents() as $event) {
                $publishedKeys[] = $event['routingKey'];
            }
        }

        foreach ($flows as $flow) {
            $mapped = $flow->commandMappings();
            foreach ($mapped as $target => $channel) {
                if ($target === $pulseServiceName) {
                    continue;
                }
                $routes[] = ['from' => $flow->name, 'to' => $target, 'channel' => $channel, 'stale' => $flow->isStale()];
                if (! in_array($target, $configuredServices, true)) {
                    $mismatches[] = new Mismatch('unconfigured-command-target', "{$flow->name} maps commands for {$target}, which is not configured in Pulse. Add {$target} to PULSE_SERVICES, or remove the command mapping from the Service Map of {$flow->name}.");
                } elseif (! in_array($target, $answering, true)) {
                    $mismatches[] = new Mismatch('silent-command-target', "{$flow->name} maps commands for {$target}, which is configured in Pulse but did not answer. Check that {$target} runs with PulseConfiguration and consumes its channel.");
                }
            }
            foreach ($flow->targetServicesSentTo() as $target) {
                if ($target !== $pulseServiceName && ! array_key_exists($target, $mapped)) {
                    $mismatches[] = new Mismatch('unmapped-command-target', "{$flow->name} sends commands to {$target}, but its Service Map has no command mapping for {$target}. Add DistributedServiceMap::initialize()->withCommandMapping('{$target}', <channel>) to a #[ServiceContext] of {$flow->name}.");
                }
            }
            foreach ($flow->eventSubscriptions() as $subscription) {
                $subscriptions[] = ['service' => $flow->name, 'channel' => $subscription['channel'], 'keys' => $subscription['subscriptionKeys'], 'stale' => $flow->isStale()];
                if ($serviceFlows->isIncomplete()) {
                    continue;
                }
                foreach ($subscription['subscriptionKeys'] as $key) {
                    if (array_filter($publishedKeys, fn (string $published) => RoutingKeyPattern::matches($key, $published)) === []) {
                        $mismatches[] = new Mismatch('unpublished-subscription', "{$flow->name} subscribes to {$key}, but no service publishes an event matching it. Publish it with DistributedBus::publishEvent(), or remove {$key} from withEventMapping().");
                    }
                }
            }
        }

        if (! $serviceFlows->isIncomplete()) {
            $linked = array_map(fn (DistributedLink $link) => $link->fromService . '|' . $link->routingKey, array_filter(DistributedLinks::between($flows), fn (DistributedLink $link) => $link->kind === 'event'));
            foreach ($flows as $flow) {
                foreach ($flow->publishedEvents() as $event) {
                    if (! in_array($flow->name . '|' . $event['routingKey'], $linked, true)) {
                        $mismatches[] = new Mismatch('unsubscribed-event', "{$flow->name} publishes {$event['routingKey']}, but no service subscribes to it. Add a #[Distributed] #[EventHandler('{$event['routingKey']}')] to a service and a withEventMapping() entry whose keys match {$event['routingKey']}.");
                    }
                }
            }
        }

        return new self($routes, $subscriptions, $mismatches, $serviceFlows->notAnswering);
    }

    public function isIncomplete(): bool
    {
        return $this->incompleteBecause !== [];
    }

    public function toMermaid(): string
    {
        $lines = ['graph LR'];
        $ids = [];
        $stale = [];
        foreach ($this->commandRoutes as $route) {
            $stale[$route['from']] = $route['stale'];
        }
        foreach ($this->commandRoutes as $route) {
            foreach ([$route['from'], $route['to']] as $service) {
                if (! isset($ids[$service])) {
                    $ids[$service] = 't' . count($ids);
                    $lines[] = sprintf('    %s["%s"]', $ids[$service], MermaidText::escape($service . (($stale[$service] ?? false) ? ' (stale)' : '')));
                }
            }
            $lines[] = sprintf('    %s -->|"%s"| %s', $ids[$route['from']], MermaidText::escape($route['channel']), $ids[$route['to']]);
        }

        return implode("\n", array_values(array_unique($lines))) . "\n";
    }
}
