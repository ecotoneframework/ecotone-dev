<?php

declare(strict_types=1);

namespace App\Ui;

use App\Snapshot\ChannelDepth;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;

final readonly class ServiceRow
{
    /**
     * @param list<ServiceLine> $lines
     * @param list<ChannelDepth> $channels
     * @param list<ChannelDepth> $tenantChannels
     * @param array{total: int, behind: int, stalled: int, failed: int} $projections
     * @param array{hub: string, failures: string, channels: string, projections: string, configuration: string} $links
     */
    public function __construct(
        public ServiceSnapshot $service,
        public array $lines,
        public array $channels,
        public array $tenantChannels,
        public ?int $failedMessages,
        public ?int $failureGroups,
        public array $projections,
        public ?string $newerEcotone,
        public array $links,
    ) {
    }

    public function status(): Status
    {
        return Status::worstOf($this->connectionStatus(), ...array_map(static fn (ServiceLine $line): Status => $line->status(), $this->lines));
    }

    public function needsAttention(): bool
    {
        foreach ($this->lines as $line) {
            foreach ($line->cards as $card) {
                if ($card->kind !== 'version-drift' && in_array(Status::of($card->severity), [Status::Critical, Status::Warning], true)) {
                    return true;
                }
            }
        }

        return in_array($this->service->status, [ServiceStatus::Unreachable, ServiceStatus::ChannelMissing], true);
    }

    public function problemCount(): int
    {
        return array_sum(array_map(static fn (ServiceLine $line): int => count($line->cards), $this->lines));
    }

    public function connectionStatus(): Status
    {
        return match ($this->service->status) {
            ServiceStatus::Connected => Status::Ok,
            ServiceStatus::Waiting => Status::Info,
            ServiceStatus::Unreachable, ServiceStatus::ChannelMissing => Status::Critical,
        };
    }

    public function connectionLabel(): string
    {
        return match ($this->service->status) {
            ServiceStatus::Connected => 'Connected',
            ServiceStatus::Waiting => 'Waiting',
            ServiceStatus::Unreachable => 'Unreachable',
            ServiceStatus::ChannelMissing => 'Channel missing',
        };
    }

    public function isMultiTenant(): bool
    {
        return $this->service->isMultiTenant();
    }

    /**
     * @return list<ServiceSnapshot>
     */
    public function tenantScopes(): array
    {
        return $this->isMultiTenant() ? array_map(static fn (ServiceLine $line): ServiceSnapshot => $line->tile->scope, $this->lines) : [];
    }

    public function tenantsSummary(): string
    {
        $tenants = $this->tenantScopes();
        $count = count($tenants) + count($this->service->unadvertisedTenants);
        $states = [];
        foreach ($tenants as $tenant) {
            $label = match ($tenant->status) {
                ServiceStatus::Connected => 'connected',
                ServiceStatus::Waiting => 'waiting',
                ServiceStatus::Unreachable => 'unreachable',
                ServiceStatus::ChannelMissing => 'channel missing',
            };
            $states[$label] = ($states[$label] ?? 0) + 1;
        }
        if ($this->service->unadvertisedTenants !== []) {
            $states['not advertised'] = count($this->service->unadvertisedTenants);
        }
        $state = array_keys($states) === ['connected'] ? 'all connected' : implode(', ', array_map(static fn (string $label, int $number): string => $number . ' ' . $label, array_keys($states), $states));

        return sprintf('%d %s · %s', $count, $count === 1 ? 'tenant' : 'tenants', $state);
    }

    public function tenantsNeedAttention(): bool
    {
        foreach ($this->tenantScopes() as $tenant) {
            if ($tenant->status !== ServiceStatus::Connected) {
                return true;
            }
        }

        return $this->service->unadvertisedTenants !== [];
    }

    public function failedMessagesStatus(): Status
    {
        return ($this->failedMessages ?? 0) > 0 ? Status::Warning : Status::Ok;
    }

    public function channelsOver(): int
    {
        return ChannelDepth::overThreshold($this->channels) + ChannelDepth::overThreshold($this->tenantChannels);
    }

    public function channelsStatus(): Status
    {
        return ChannelDepth::statusOf([...$this->channels, ...$this->tenantChannels]);
    }

    public function channelsState(): string
    {
        return ChannelDepth::stateOf([...$this->channels, ...$this->tenantChannels]);
    }

    public function projectionsStatus(): Status
    {
        return match (true) {
            $this->projections['failed'] > 0 => Status::Critical,
            $this->projections['behind'] > 0 || $this->projections['stalled'] > 0 => Status::Warning,
            default => Status::Ok,
        };
    }

    public function projectionsState(): string
    {
        $total = $this->projections['total'];
        $parts = array_filter(['behind' => $this->projections['behind'], 'stalled' => $this->projections['stalled'], 'failed' => $this->projections['failed']]);

        return $parts === []
            ? ($total === 1 ? 'up to date' : 'all up to date')
            : implode(' · ', array_map(static fn (string $state, int $count): string => sprintf('%d of %d %s', $count, $total, $state), array_keys($parts), $parts));
    }

    public function licenceState(): string
    {
        $licence = $this->service->snapshot['overview']['licence'] ?? [];

        return match (true) {
            ! is_array($licence) => 'licence unknown',
            (bool) ($licence['testing'] ?? false) => 'testing licence',
            is_string($licence['expiresAt'] ?? null) => 'licence expires ' . substr($licence['expiresAt'], 0, 10),
            default => 'licence unknown',
        };
    }
}
