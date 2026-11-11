<?php

declare(strict_types=1);

namespace App\Graph;

final readonly class TopologyReport
{
    /**
     * @param list<array{from: string, to: string, channel: string, stale: bool}> $commandRoutes
     * @param list<array{service: string, channel: string, keys: list<string>, stale: bool, include: list<string>, exclude: list<string>}> $eventSubscriptions
     * @param array<string, string> $serviceByChannel
     * @param list<Mismatch> $mismatches
     * @param list<string> $incompleteBecause
     */
    private function __construct(public array $commandRoutes, public array $eventSubscriptions, public array $mismatches, public array $incompleteBecause, private array $serviceByChannel, private ?ServiceScopeFilter $scope = null)
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
                $publishedKeys[] = ['service' => $flow->name, 'routingKey' => $event['routingKey']];
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
                    $mismatches[] = new Mismatch(
                        'unconfigured-command-target',
                        "{$flow->name} maps commands for {$target}, which is not configured in Pulse.",
                        "Pulse does not ask {$target} for a snapshot, so it cannot check that {$target} runs and handles the commands {$flow->name} sends it.",
                        "Add {$target} to PULSE_SERVICES, or remove the command mapping from the Service Map of {$flow->name}.",
                        [$flow->name, $target],
                        [$flow->name, $target],
                    );
                } elseif (! in_array($target, $answering, true)) {
                    $mismatches[] = new Mismatch(
                        'silent-command-target',
                        "{$flow->name} maps commands for {$target}, which is configured in Pulse but did not answer.",
                        "If {$target} is not consuming channel {$channel}, the commands {$flow->name} sends there wait and no handler runs them.",
                        "Check that {$target} runs with PulseConfiguration and consumes its channel.",
                        [$flow->name, $target],
                        [$flow->name, $target],
                    );
                }
            }
            foreach ($flow->targetServicesSentTo() as $target) {
                if ($target !== $pulseServiceName && ! array_key_exists($target, $mapped)) {
                    $mismatches[] = new Mismatch(
                        'unmapped-command-target',
                        "{$flow->name} sends commands to {$target}, but its Service Map has no command mapping for {$target}.",
                        "The Distributed Bus has no channel for {$target}, so each of these sends fails with UnknownDistributedDestination and the command is not delivered.",
                        "Add DistributedServiceMap::initialize()->withCommandMapping('{$target}', <channel>) to a #[ServiceContext] of {$flow->name}.",
                        [$flow->name, $target],
                        [$flow->name, $target],
                    );
                }
            }
            foreach ($flow->eventSubscriptions() as $subscription) {
                $subscriptions[] = ['service' => $flow->name, 'channel' => $subscription['channel'], 'keys' => $subscription['subscriptionKeys'], 'stale' => $flow->isStale(), 'include' => $subscription['includePublishingServices'], 'exclude' => $subscription['excludePublishingServices']];
                if ($serviceFlows->isIncomplete()) {
                    continue;
                }
                foreach ($subscription['subscriptionKeys'] as $key) {
                    $accepted = ['channel' => $subscription['channel'], 'subscriptionKeys' => [$key], 'excludePublishingServices' => $subscription['excludePublishingServices'], 'includePublishingServices' => $subscription['includePublishingServices']];
                    if (array_filter($publishedKeys, fn (array $published) => ServiceFlow::subscriptionAccepts($accepted, $published['service'], $published['routingKey'])) === []) {
                        $mismatches[] = new Mismatch(
                            'unpublished-subscription',
                            "{$flow->name} subscribes to {$key}, but no service publishes an event matching it.",
                            "Nothing is sent over this route, so no handler consuming channel {$subscription['channel']} receives an event matching {$key}.",
                            "Publish it with DistributedBus::publishEvent(), or remove {$key} from withEventMapping().",
                            [$flow->name],
                            [$flow->name, $key],
                        );
                    }
                }
            }
        }

        if (! $serviceFlows->isIncomplete()) {
            $linked = array_map(fn (DistributedLink $link) => $link->fromService . '|' . $link->routingKey, array_filter(DistributedLinks::between($flows), fn (DistributedLink $link) => $link->kind === 'event'));
            foreach ($flows as $flow) {
                foreach ($flow->publishedEvents() as $event) {
                    if (! in_array($flow->name . '|' . $event['routingKey'], $linked, true)) {
                        $mismatches[] = new Mismatch(
                            'unsubscribed-event',
                            "{$flow->name} publishes {$event['routingKey']}, but no service subscribes to it.",
                            "The Service Map of {$flow->name} routes {$event['routingKey']} to no service with a #[Distributed] handler for it, so no other service reacts to it.",
                            "Add a #[Distributed] #[EventHandler('{$event['routingKey']}')] to a service and a withEventMapping() entry whose keys match {$event['routingKey']}.",
                            [$flow->name],
                            [$flow->name, $event['routingKey']],
                        );
                    }
                }
            }
        }

        return new self($routes, $subscriptions, $mismatches, $serviceFlows->notAnswering, $serviceFlows->serviceByChannel);
    }

    public function narrowedTo(ServiceScopeFilter $scope): self
    {
        return new self(
            array_values(array_filter($this->commandRoutes, static fn (array $route): bool => $scope->involves($route['from'], $route['to']))),
            array_values(array_filter($this->eventSubscriptions, fn (array $subscription): bool => $scope->involves($subscription['service'], $this->serviceByChannel[$subscription['channel']] ?? ''))),
            array_values(array_filter($this->mismatches, static fn (Mismatch $mismatch): bool => $scope->involves(...$mismatch->services))),
            $this->incompleteBecause,
            $this->serviceByChannel,
            $scope,
        );
    }

    public function serviceOnChannel(string $channel): ?string
    {
        return $this->serviceByChannel[$channel] ?? null;
    }

    public function hasConnections(): bool
    {
        return ! $this->toDiagram()->isEmpty();
    }

    public function isIncomplete(): bool
    {
        return $this->incompleteBecause !== [];
    }

    public function toDiagram(): Diagram
    {
        $ids = [];
        $nodes = [];
        $edges = [];
        $stale = [];
        foreach ($this->commandRoutes as $route) {
            $stale[$route['from']] = $route['stale'];
        }
        $nodeOf = function (string $service) use (&$ids, &$nodes, $stale): string {
            if (! isset($ids[$service])) {
                $ids[$service] = 't' . count($ids);
                $nodes[] = new DiagramNode($ids[$service], null, 'service', $service, ($stale[$service] ?? false) ? 'stale' : null, null, $this->scope?->isGreyed($service) === true);
            }

            return $ids[$service];
        };
        foreach ($this->commandRoutes as $route) {
            $edge = new DiagramEdge($nodeOf($route['from']), $nodeOf($route['to']), DiagramEdge::SOLID, 'commands via ' . $route['channel']);
            $edges[$edge->key()] = $edge;
        }

        foreach ($this->eventSubscriptions as $subscription) {
            $subscriber = $this->serviceByChannel[$subscription['channel']] ?? null;
            $publisherPermitted = ($subscription['include'] === [] || in_array($subscription['service'], $subscription['include'], true)) && ! in_array($subscription['service'], $subscription['exclude'], true);
            if ($subscriber === null || $subscriber === $subscription['service'] || ! $publisherPermitted) {
                continue;
            }
            $edge = new DiagramEdge($nodeOf($subscription['service']), $nodeOf($subscriber), DiagramEdge::DASHED, 'events ' . implode(', ', $subscription['keys']));
            $edges[$edge->key()] = $edge;
        }

        return new Diagram([], $nodes, array_values($edges));
    }
}
