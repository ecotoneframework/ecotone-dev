<?php

declare(strict_types=1);

namespace App\Snapshot;

use App\Settings\PulseSettings;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

final readonly class Snapshots
{
    public const FRESHNESS_SECONDS = 60;

    public function __construct(
        private SnapshotStore $store,
        private SnapshotRequester $requester,
        private ReplyAwaiter $awaiter,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
        private PulseSettings $settings,
    ) {
    }

    /**
     * @return list<ServiceSnapshot>
     */
    public function viewAndRequestStale(): array
    {
        $this->requestEveryScope(force: false);

        return $this->all();
    }

    /**
     * @return list<ServiceSnapshot>
     */
    public function viewScopesAndRequestStale(): array
    {
        $this->requestEveryScope(force: false);

        return $this->scopes();
    }

    public function refresh(): void
    {
        $this->requestEveryScope(force: true);
    }

    public function refreshIdleServices(): void
    {
        foreach (array_keys($this->settings->serviceChannels) as $service) {
            $this->requester->refresh(ServiceScope::ofService($service));
        }
        foreach ($this->tenantScopes() as $scope) {
            $this->requester->refresh($scope);
        }
    }

    /**
     * @return list<ServiceSnapshot>
     */
    public function all(): array
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        return array_map(fn (string $service): ServiceSnapshot => $this->snapshotOf(ServiceScope::ofService($service), $now), array_keys($this->settings->serviceChannels));
    }

    /**
     * @return list<ServiceSnapshot>
     */
    public function scopes(): array
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $scopes = [];
        foreach ($this->all() as $service) {
            if (! $service->isMultiTenant()) {
                $scopes[] = $service;
                continue;
            }

            foreach ($service->tenants as $tenant) {
                $scopes[] = $this->snapshotOf(ServiceScope::ofTenant($service->service, $tenant), $now, $service);
            }
        }

        return $scopes;
    }

    /**
     * @return array<string, list<string>>
     */
    public function discoveredTenants(): array
    {
        $tenants = [];
        try {
            foreach ($this->all() as $service) {
                if ($service->tenants !== []) {
                    $tenants[$service->service] = $service->tenants;
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $tenants;
    }

    private function requestEveryScope(bool $force): void
    {
        $this->awaiter->await(array_map(fn (string $service) => $this->requester->request(ServiceScope::ofService($service), $force), array_keys($this->settings->serviceChannels)));
        $this->awaiter->await(array_map(fn (ServiceScope $scope) => $this->requester->request($scope, $force), $this->tenantScopes()));
    }

    /**
     * @return list<ServiceScope>
     */
    private function tenantScopes(): array
    {
        $scopes = [];
        foreach ($this->all() as $service) {
            foreach ($service->tenants as $tenant) {
                $scopes[] = ServiceScope::ofTenant($service->service, $tenant);
            }
        }

        return $scopes;
    }

    /**
     * @param array<string, mixed> $service
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    private static function mergedWithTenant(array $service, array $tenant): array
    {
        $measured = [];
        foreach (is_array($tenant['channels'] ?? null) ? $tenant['channels'] : [] as $channel) {
            if (is_array($channel) && is_string($channel['name'] ?? null)) {
                $measured[$channel['name']] = $channel + ['perTenant' => true];
            }
        }
        unset($tenant['channels']);
        $merged = array_replace_recursive($service, $tenant);
        $merged['channels'] = array_map(static fn (mixed $channel): mixed => is_array($channel) ? ($measured[$channel['name'] ?? ''] ?? $channel) : $channel, is_array($service['channels'] ?? null) ? $service['channels'] : []);

        return $merged;
    }

    private function snapshotOf(ServiceScope $scope, DateTimeImmutable $now, ?ServiceSnapshot $service = null): ServiceSnapshot
    {
        $record = $this->store->read($scope);
        $tenancy = $service === null ? $record->snapshot['tenancy'] ?? null : null;
        $advertised = is_array($tenancy) && is_array($tenancy['tenants'] ?? null) ? array_values(array_filter($tenancy['tenants'], is_string(...))) : [];
        $narrowing = $this->settings->routes[$scope->service]->tenants;
        $tenants = $narrowing === null ? $advertised : array_values(array_filter($narrowing, static fn (string $tenant): bool => in_array($tenant, $advertised, true)));
        $unadvertised = $service !== null || ! is_array($tenancy) && $record->receivedAt === null ? [] : array_values(array_diff($narrowing ?? [], $advertised));

        return new ServiceSnapshot(
            $scope->service,
            $this->settings->serviceChannels[$scope->service],
            $record->statusAt($now, $this->settings->replyTimeoutSeconds),
            $record->receivedAt,
            $record->ageAt($now),
            $service === null ? $record->snapshot : self::mergedWithTenant($service->snapshot, $record->snapshot),
            $record->requestFailure,
            $record->replyError,
            $record->hasPendingFollowUp(),
            $record->retrying,
            $scope->tenant,
            is_array($tenancy) && is_string($tenancy['header'] ?? null) ? $tenancy['header'] : $service?->tenantHeader,
            $service === null ? $tenants : [],
            $unadvertised,
            $record->projectionMovements,
            $record->deadLettersGrewBy,
            $service === null ? $record->channelMovements : [...$service->channelMovements, ...$record->channelMovements],
            $record->refusedRequests,
        );
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
