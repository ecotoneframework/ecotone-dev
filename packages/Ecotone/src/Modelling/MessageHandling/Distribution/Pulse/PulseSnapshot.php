<?php

declare(strict_types=1);

namespace Ecotone\Modelling\MessageHandling\Distribution\Pulse;

use Composer\InstalledVersions;
use DateTimeInterface;
use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class PulseSnapshot
{
    public const SCHEMA_VERSION = 1;
    public const ROUTING_KEY = 'ecotone.pulse.snapshot';
    public const CORE_KEYS = ['schemaVersion', 'serviceName', 'takenAt', 'overview', 'flowGraph', 'topology', 'channels', 'tenancy', 'tenant'];

    /**
     * @param array<array{referenceName: string, commandMapping: array<string, string>, eventSubscriptions: array<array{channel: string, subscriptionKeys: string[], excludePublishingServices: string[], includePublishingServices: string[]}>, asynchronousChannel: string|null}> $serviceMaps
     * @param list<array{name: string, kind: string}> $channels
     * @param array<string, PulseSnapshotSection> $tenantIndependentSections
     * @param array<string, PulseSnapshotSection> $tenantScopedSections
     * @param array{header: string, tenants: list<string>}|array{} $tenancy
     */
    public function __construct(
        private string $serviceName,
        private string $pulseServiceName,
        private string $framework,
        private array $serviceMaps,
        private array $channels,
        private array $tenantIndependentSections,
        private array $tenantScopedSections,
        private array $tenancy,
        private ConfiguredMessagingSystem $messagingSystem,
        private EcotoneClockInterface $clock,
    ) {
    }

    public static function isSnapshotRequest(Message $message): bool
    {
        return $message->getHeaders()->containsKey(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY) && $message->getHeaders()->get(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY) === self::ROUTING_KEY;
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function snapshotJson(?string $sourceService, array $headers): string
    {
        $this->authorizeRequestFrom($sourceService);

        $tenant = $this->isMultiTenant() && isset($headers[$this->tenancy['header']]) ? $this->advertisedTenant($headers[$this->tenancy['header']]) : null;

        return json_encode($tenant === null ? $this->snapshot() : $this->tenantSnapshot($tenant), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function tenantOfScopedRequest(array $headers): ?string
    {
        if (! $this->isMultiTenant() || ! isset($headers[$this->tenancy['header']])) {
            return null;
        }

        return $this->advertisedTenant($headers[$this->tenancy['header']]);
    }

    private function isMultiTenant(): bool
    {
        return $this->tenancy !== [];
    }

    private function advertisedTenant(mixed $tenant): string
    {
        if (! is_string($tenant) || ! in_array($tenant, $this->tenancy['tenants'], true)) {
            $named = is_scalar($tenant) ? (string) $tenant : get_debug_type($tenant);
            throw InvalidArgumentException::create("Service {$this->serviceName} was asked by Pulse for tenant `{$named}`, but it advertises only " . implode(', ', $this->tenancy['tenants']) . ", so the answer would come from the default connection and be shown as that tenant. Ask for one of the advertised tenants, or add `{$named}` to the tenant mapping of MultiTenantConfiguration.");
        }

        return $tenant;
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantSnapshot(string $tenant): array
    {
        $snapshot = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'serviceName' => $this->serviceName,
            'takenAt' => $this->clock->now()->format(DateTimeInterface::ATOM),
            'tenant' => $tenant,
        ];

        foreach ($this->tenantScopedSections as $sectionName => $section) {
            $snapshot[$sectionName] = $section->snapshot();
        }

        return $snapshot;
    }

    /**
     * @return array{
     *     schemaVersion: int,
     *     serviceName: string,
     *     takenAt: string,
     *     overview: array{ecotoneVersion: string|null, phpVersion: string, framework: string, licence: array{enterprise: bool, expiresAt: string|null}},
     *     flowGraph: array<string, mixed>,
     *     topology: array{serviceMaps: array<mixed>},
     *     channels: list<array{name: string, kind: string}>,
     * }&array<string, mixed>
     */
    private function snapshot(): array
    {
        $snapshot = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'serviceName' => $this->serviceName,
            'takenAt' => $this->clock->now()->format(DateTimeInterface::ATOM),
            'overview' => [
                'ecotoneVersion' => InstalledVersions::isInstalled('ecotone/ecotone') ? InstalledVersions::getPrettyVersion('ecotone/ecotone') : null,
                'phpVersion' => PHP_VERSION,
                'framework' => $this->framework,
                'licence' => ['enterprise' => true, 'expiresAt' => null],
            ],
            'flowGraph' => $this->messagingSystem->getMessageFlowGraph()->toArray(),
            'topology' => ['serviceMaps' => $this->serviceMaps],
            'channels' => $this->channels,
        ];

        if ($this->isMultiTenant()) {
            $snapshot['tenancy'] = $this->tenancy;
        }

        foreach ($this->tenantIndependentSections as $sectionName => $section) {
            $snapshot[$sectionName] = $section->snapshot();
        }
        if (! $this->isMultiTenant()) {
            foreach ($this->tenantScopedSections as $sectionName => $section) {
                $snapshot[$sectionName] = [...($snapshot[$sectionName] ?? []), ...$section->snapshot()];
            }
        }

        return $snapshot;
    }

    public function authorizeRequestFrom(?string $sourceService): void
    {
        if ($sourceService === $this->pulseServiceName) {
            return;
        }

        $received = $sourceService ?? 'no service';
        throw InvalidArgumentException::create("Service {$this->serviceName} was asked for its Pulse snapshot by {$received}, but it only answers the configured Pulse service {$this->pulseServiceName}. Send the request from the Pulse service, or change the accepted service with PulseConfiguration::createWithDefaults(pulseServiceName: '{$received}') in a #[ServiceContext].");
    }
}
