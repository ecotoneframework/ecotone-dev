<?php

declare(strict_types=1);

namespace App\Snapshot;

use App\Settings\PulseSettings;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class Snapshots
{
    public const FRESHNESS_SECONDS = 60;

    public function __construct(
        private SnapshotStore $store,
        private SnapshotRequester $requester,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
        private PulseSettings $settings,
    ) {
    }

    public function requestStaleSnapshots(bool $force = false): void
    {
        $now = $this->now();
        foreach (array_keys($this->settings->serviceChannels) as $service) {
            if ($force || $this->shouldRequest($service, $now)) {
                $this->requester->request($service);
            }
        }
    }

    public function waitForReplies(): void
    {
        $deadline = hrtime(true) + $this->settings->renderWaitSeconds * 1_000_000_000;
        while (hrtime(true) < $deadline && $this->anyWaiting()) {
            usleep(100_000);
        }
    }

    /**
     * @return list<ServiceSnapshot>
     */
    public function all(): array
    {
        $now = $this->now();

        return array_map(fn (string $service) => $this->of($service, $now), array_keys($this->settings->serviceChannels));
    }

    public function anyWaiting(): bool
    {
        foreach ($this->all() as $snapshot) {
            if ($snapshot->status === ServiceStatus::Waiting) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     * @return list<string>
     */
    public static function ecotoneVersions(array $snapshots): array
    {
        $versions = array_values(array_unique(array_filter(array_map(static fn (ServiceSnapshot $snapshot) => $snapshot->ecotoneVersion(), $snapshots))));
        usort($versions, 'version_compare');

        return $versions;
    }

    private function shouldRequest(string $service, DateTimeImmutable $now): bool
    {
        $stored = $this->store->snapshotOf($service);
        if ($stored !== null && $this->secondsBetween($stored['receivedAt'], $now) < self::FRESHNESS_SECONDS) {
            return false;
        }

        $requestedAt = $this->store->requestedAt($service);

        return $requestedAt === null || $this->secondsBetween($requestedAt, $now) >= self::FRESHNESS_SECONDS;
    }

    private function of(string $service, DateTimeImmutable $now): ServiceSnapshot
    {
        $stored = $this->store->snapshotOf($service);
        $requestedAt = $this->store->requestedAt($service);
        $receivedAt = $stored['receivedAt'] ?? null;
        $age = $receivedAt === null ? null : $this->secondsBetween($receivedAt, $now);

        $status = match (true) {
            $age !== null && $age < self::FRESHNESS_SECONDS => ServiceStatus::Connected,
            $requestedAt !== null && ($receivedAt === null || $requestedAt > $receivedAt) && $this->secondsBetween($requestedAt, $now) >= $this->settings->replyTimeoutSeconds => ServiceStatus::Unreachable,
            default => ServiceStatus::Waiting,
        };

        return new ServiceSnapshot($service, $status, $receivedAt, $age, $stored['snapshot'] ?? []);
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private function secondsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return $to->getTimestamp() - $from->getTimestamp();
    }
}
