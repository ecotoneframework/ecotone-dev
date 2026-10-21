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

    /**
     * @return list<ServiceSnapshot>
     */
    public function viewAndRequestStale(): array
    {
        foreach (array_keys($this->settings->serviceChannels) as $service) {
            $this->requester->request($service, force: false);
        }

        return $this->all();
    }

    public function refresh(): void
    {
        foreach (array_keys($this->settings->serviceChannels) as $service) {
            $this->requester->request($service, force: true);
        }
    }

    public function refreshIdleServices(): void
    {
        foreach (array_keys($this->settings->serviceChannels) as $service) {
            $this->requester->refresh($service);
        }
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     * @return list<ServiceSnapshot>
     */
    public function waitForReplies(array $snapshots): array
    {
        $deadline = hrtime(true) + $this->settings->renderWaitSeconds * 1_000_000_000;
        while (hrtime(true) < $deadline && self::anyWaiting($snapshots)) {
            usleep(100_000);
            $snapshots = $this->all();
        }

        return $snapshots;
    }

    /**
     * @return list<ServiceSnapshot>
     */
    public function all(): array
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        return array_map(function (string $service) use ($now): ServiceSnapshot {
            $record = $this->store->read($service);

            return new ServiceSnapshot(
                $service,
                $record->statusAt($now, $this->settings->replyTimeoutSeconds),
                $record->receivedAt,
                $record->ageAt($now),
                $record->snapshot,
                $record->requestFailure,
                $record->replyError,
                $record->hasPendingFollowUp(),
            );
        }, array_keys($this->settings->serviceChannels));
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     */
    public static function anyWaiting(array $snapshots): bool
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->status === ServiceStatus::Waiting) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     */
    public static function needsAutoRefresh(array $snapshots): bool
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->followUpPending) {
                return true;
            }
        }

        return self::anyWaiting($snapshots);
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
}
