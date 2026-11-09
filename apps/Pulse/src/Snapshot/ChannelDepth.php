<?php

declare(strict_types=1);

namespace App\Snapshot;

use App\Settings\QueueThresholds;
use App\Ui\Status;
use DateTimeImmutable;

final readonly class ChannelDepth
{
    public function __construct(
        public string $name,
        public string $kind,
        public ?int $ready,
        public ?int $delayed,
        public ?int $inFlight,
        public ?int $consumers,
        public ?string $probeError,
        public string $level,
        public bool $perTenant,
        public ?string $consumerToRun = null,
        public array $handlers = [],
        public ?bool $setUp = null,
        public ?int $notDrainingSeconds = null,
    ) {
    }

    /**
     * @return list<self>
     */
    public static function of(ServiceSnapshot $snapshot, QueueThresholds $thresholds, int $stalledAfterSeconds): array
    {
        $channels = $snapshot->snapshot['channels'] ?? [];
        if (! is_array($channels)) {
            return [];
        }

        $depths = [];
        foreach ($channels as $channel) {
            if (! is_array($channel) || ! is_string($channel['name'] ?? null)) {
                continue;
            }
            $ready = self::count($channel, 'ready');
            $depths[] = new self(
                $channel['name'],
                is_string($channel['kind'] ?? null) ? $channel['kind'] : 'queue',
                $ready,
                self::count($channel, 'delayed'),
                self::count($channel, 'inFlight'),
                self::count($channel, 'consumers'),
                is_string($channel['probeError'] ?? null) ? $channel['probeError'] : null,
                $thresholds->levelOf($snapshot->service, $channel['name'], $ready),
                ($channel['perTenant'] ?? false) === true,
                is_string($channel['consumerToRun'] ?? null) ? $channel['consumerToRun'] : null,
                is_array($channel['handlers'] ?? null) ? array_values(array_filter($channel['handlers'], is_string(...))) : [],
                is_bool($channel['setUp'] ?? null) ? $channel['setUp'] : null,
                self::notDrainingSeconds($snapshot, $channel['name'], $ready, $stalledAfterSeconds),
            );
        }

        return $depths;
    }

    public function needsAttention(): bool
    {
        return in_array($this->level, ['warning', 'critical'], true) || $this->notDrainingSeconds !== null || $this->setUp === false;
    }

    public function status(): Status
    {
        return match (true) {
            $this->level === 'critical' || $this->setUp === false => Status::Critical,
            $this->level === 'warning' || $this->notDrainingSeconds !== null => Status::Warning,
            default => Status::Ok,
        };
    }

    public function depthReason(bool $measuredPerTenant): ?string
    {
        return match (true) {
            $this->ready !== null => null,
            $this->probeError !== null => sprintf('Pulse could not read the depth of %s: %s', $this->name, $this->probeError),
            $measuredPerTenant => sprintf('%s is on the tenant connection, so its depth is measured per tenant. Pick a tenant to see it.', $this->name),
            default => sprintf('The transport of %s does not report how many messages wait on it. That is normal for this kind of channel and not a fault: Pulse just cannot show its depth.', $this->name),
        };
    }

    public function depthShortReason(bool $measuredPerTenant): ?string
    {
        return match (true) {
            $this->ready !== null => null,
            $this->probeError !== null => 'depth could not be read',
            $measuredPerTenant => 'depth measured per tenant',
            default => 'its transport does not report queue size, which is normal and not a fault',
        };
    }

    /**
     * @param list<self> $depths
     * @return list<self>
     */
    public static function byUrgency(array $depths): array
    {
        $ranked = array_map(static fn (self $depth, int $position): array => [$depth, $position], $depths, array_keys($depths));
        usort($ranked, static fn (array $first, array $second): int => [-$first[0]->urgency(), $first[1]] <=> [-$second[0]->urgency(), $second[1]]);

        return array_column($ranked, 0);
    }

    /**
     * @param list<self> $depths
     */
    public static function stateOf(array $depths): string
    {
        $over = count(array_filter($depths, static fn (self $depth): bool => in_array($depth->level, ['warning', 'critical'], true)));
        $notDraining = count(array_filter($depths, static fn (self $depth): bool => $depth->notDrainingSeconds !== null));
        $notSetUp = count(array_filter($depths, static fn (self $depth): bool => $depth->setUp === false));
        $parts = array_filter([
            $over > 0 ? sprintf('%d over %s threshold', $over, $over === 1 ? 'its' : 'their') : null,
            $notDraining > 0 ? sprintf('%d not draining', $notDraining) : null,
            $notSetUp > 0 ? sprintf('%d not set up', $notSetUp) : null,
        ]);

        return $parts === [] ? 'none stuck' : implode(', ', $parts);
    }

    /**
     * @param list<self> $depths
     */
    public static function statusOf(array $depths): Status
    {
        return Status::worstOf(...array_map(static fn (self $depth): Status => $depth->status(), $depths));
    }

    private function urgency(): int
    {
        return match (true) {
            $this->status() === Status::Critical => 4,
            $this->status() === Status::Warning => 3,
            $this->ready === null => 2,
            ($this->ready ?? 0) > 0 => 1,
            default => 0,
        };
    }

    public function runCommand(): ?string
    {
        return $this->consumerToRun === null ? null : 'ecotone:run ' . $this->consumerToRun;
    }

    /**
     * @param list<self> $depths
     */
    public static function overThreshold(array $depths): int
    {
        return count(array_filter($depths, static fn (self $depth) => in_array($depth->level, ['warning', 'critical'], true)));
    }

    private static function notDrainingSeconds(ServiceSnapshot $snapshot, string $name, ?int $ready, int $stalledAfterSeconds): ?int
    {
        $since = $snapshot->channelMovements[$name]['since'] ?? null;
        if ($ready === null || $ready <= 0 || $since === null || $snapshot->lastSeenAt === null) {
            return null;
        }

        $seconds = $snapshot->lastSeenAt->getTimestamp() - (new DateTimeImmutable($since))->getTimestamp();

        return $seconds > $stalledAfterSeconds ? $seconds : null;
    }

    /**
     * @param array<mixed> $channel
     */
    private static function count(array $channel, string $field): ?int
    {
        return is_int($channel[$field] ?? null) ? $channel[$field] : null;
    }
}
