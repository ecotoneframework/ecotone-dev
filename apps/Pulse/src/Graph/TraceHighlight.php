<?php

declare(strict_types=1);

namespace App\Graph;

use App\Trace\CrossServiceTrace;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;

final readonly class TraceHighlight
{
    public const PASSED = 'tracePassed';
    public const PENDING = 'tracePending';
    public const FAILED = 'traceFailed';
    private const PRECEDENCE = [self::PASSED => 1, self::PENDING => 2, self::FAILED => 3];

    /**
     * @param array<string, array<string, string>> $nodeStates
     * @param array<string, array<string, int>> $durationsInMicroseconds
     * @param array<string, true> $traversedLinks
     * @param list<string> $services
     */
    private function __construct(
        public string $traceId,
        public string $status,
        private array $nodeStates,
        private array $durationsInMicroseconds,
        private array $traversedLinks,
        public array $services,
    ) {
    }

    /**
     * @param list<ServiceFlow> $flows
     */
    public static function of(string $traceId, CrossServiceTrace $trace, array $flows): self
    {
        $states = [];
        $durations = [];
        $hops = [];
        $services = [];
        foreach ($trace->rows as $index => $row) {
            $services[$row['service']] = true;
            if ($row['hop'] && is_string($row['parentService'])) {
                $hops[] = ['from' => $row['parentService'], 'to' => $row['service'], 'channel' => $row['channel'], 'entries' => self::entriesOf($trace->rows, $index)];
            }
        }
        foreach ($flows as $flow) {
            foreach ($trace->rows as $row) {
                if ($row['service'] !== $flow->name) {
                    continue;
                }
                $state = $row['failed'] ? self::FAILED : ($row['pending'] ? self::PENDING : self::PASSED);
                foreach ($flow->graph->nodes() as $node) {
                    if (self::spanPassesThrough($row, $node)) {
                        $states[$flow->name][$node->id] = self::stronger($states[$flow->name][$node->id] ?? null, $state);
                        $durations[$flow->name][$node->id] = max($durations[$flow->name][$node->id] ?? 0, $row['durationMicro']);
                    }
                }
            }
        }

        $traversed = [];
        foreach (DistributedLinks::between($flows) as $link) {
            if (isset($states[$link->toService][$link->toNodeId]) && self::hopCarries($hops, $link)) {
                $traversed[self::linkKey($link)] = true;
                $states[$link->fromService][$link->fromNodeId] ??= self::PASSED;
            }
        }

        return new self($traceId, $trace->status, $states, $durations, $traversed, array_keys($services));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function entriesOf(array $rows, int $receiveIndex): array
    {
        $entries = [];
        for ($next = $receiveIndex + 1; isset($rows[$next]) && $rows[$next]['depth'] > $rows[$receiveIndex]['depth']; $next++) {
            if ($rows[$next]['depth'] === $rows[$receiveIndex]['depth'] + 1 && $rows[$next]['destination'] !== null && str_starts_with($rows[$next]['name'], 'send ')) {
                $entries[] = $rows[$next]['name'] . '|' . $rows[$next]['destination'];
            }
        }

        return $entries;
    }

    /**
     * @param list<array{from: string, to: string, channel: ?string, entries: list<string>}> $hops
     */
    private static function hopCarries(array $hops, DistributedLink $link): bool
    {
        foreach ($hops as $hop) {
            if ($hop['from'] !== $link->fromService || $hop['to'] !== $link->toService) {
                continue;
            }
            $carried = match ($link->kind) {
                'command' => in_array('send command|' . $link->routingKey, $hop['entries'], true),
                'event' => in_array('send event|' . $link->routingKey, $hop['entries'], true),
                'message' => $hop['channel'] === $link->routingKey,
                default => true,
            };
            if ($carried) {
                return true;
            }
        }

        return false;
    }

    public function stateOf(string $service, string $nodeId): ?string
    {
        return $this->nodeStates[$service][$nodeId] ?? null;
    }

    public function stateOfService(string $service): ?string
    {
        $state = null;
        foreach ($this->nodeStates[$service] ?? [] as $nodeState) {
            $state = self::stronger($state, $nodeState);
        }

        return $state;
    }

    public function durationLabelOf(string $service, string $nodeId): ?string
    {
        $microseconds = $this->durationsInMicroseconds[$service][$nodeId] ?? null;

        return $microseconds === null ? null : rtrim(rtrim(number_format($microseconds / 1000, 1, '.', ''), '0'), '.') . ' ms';
    }

    public function passes(string $service, string $fromNodeId, string $toNodeId): bool
    {
        return isset($this->nodeStates[$service][$fromNodeId], $this->nodeStates[$service][$toNodeId]);
    }

    public function traverses(DistributedLink $link): bool
    {
        return isset($this->traversedLinks[self::linkKey($link)]);
    }

    /**
     * @return array<string, int>
     */
    public function countsByState(): array
    {
        $counts = [self::PASSED => 0, self::PENDING => 0, self::FAILED => 0];
        foreach ($this->nodeStates as $nodes) {
            foreach ($nodes as $state) {
                $counts[$state]++;
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function spanPassesThrough(array $row, FlowNode $node): bool
    {
        $endpoint = $row['endpoint'];
        if ($endpoint !== '' && ($node->attributes['endpointId'] ?? null) === $endpoint) {
            return true;
        }
        if ($row['channel'] !== null) {
            return $node->kind === FlowNodeKind::CHANNEL && $node->label === $row['channel'];
        }
        if ($row['destination'] !== null && str_starts_with($row['name'], 'send ') && $node->kind->isMessage()) {
            return 'send ' . $node->kind->value === $row['name'] && ServiceFlow::routingKeyOf($node) === $row['destination'];
        }
        if ($row['function'] !== null && $node->kind->isHandler() && isset($node->attributes['class'], $node->attributes['method'])) {
            return $row['function'] === $node->attributes['class'] . '::' . $node->attributes['method'];
        }

        return false;
    }

    private static function stronger(?string $current, string $candidate): string
    {
        return $current === null || self::PRECEDENCE[$candidate] > self::PRECEDENCE[$current] ? $candidate : $current;
    }

    private static function linkKey(DistributedLink $link): string
    {
        return implode('|', [$link->fromService, $link->fromNodeId, $link->toService, $link->toNodeId, $link->kind]);
    }
}
