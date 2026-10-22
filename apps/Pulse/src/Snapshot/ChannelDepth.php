<?php

declare(strict_types=1);

namespace App\Snapshot;

use App\Settings\QueueThresholds;
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
        public array $expectedConsumers = [],
        public ?bool $setUp = null,
        public ?int $notConsumingSeconds = null,
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
                is_array($channel['expectedConsumers'] ?? null) ? array_values(array_filter($channel['expectedConsumers'], is_string(...))) : [],
                is_bool($channel['setUp'] ?? null) ? $channel['setUp'] : null,
                self::notConsumingSeconds($snapshot, $channel['name'], $ready, $stalledAfterSeconds),
            );
        }

        return $depths;
    }

    /**
     * @param list<self> $depths
     */
    public static function overThreshold(array $depths): int
    {
        return count(array_filter($depths, static fn (self $depth) => in_array($depth->level, ['warning', 'critical'], true)));
    }

    private static function notConsumingSeconds(ServiceSnapshot $snapshot, string $name, ?int $ready, int $stalledAfterSeconds): ?int
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
