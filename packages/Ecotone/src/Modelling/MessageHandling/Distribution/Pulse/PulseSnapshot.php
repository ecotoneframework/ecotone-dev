<?php

declare(strict_types=1);

namespace Ecotone\Modelling\MessageHandling\Distribution\Pulse;

use Composer\InstalledVersions;
use DateTimeInterface;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Throwable;

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
    public const PROBE_BUDGET_IN_MILLISECONDS = 2000;
    public const CORE_KEYS = ['schemaVersion', 'serviceName', 'takenAt', 'overview', 'flowGraph', 'topology', 'channels'];

    /**
     * @param array<array{referenceName: string, commandMapping: array<string, string>, eventSubscriptions: array<array{channel: string, subscriptionKeys: string[], excludePublishingServices: string[], includePublishingServices: string[]}>, asynchronousChannel: string|null}> $serviceMaps
     * @param list<array{name: string, kind: string, probeGroup: string|null, destination: string|null}> $channels
     * @param array<string, PulseChannelProbe> $probes
     * @param array<string, PulseSnapshotSection> $sections
     */
    public function __construct(
        private string $serviceName,
        private string $pulseServiceName,
        private string $framework,
        private array $serviceMaps,
        private array $channels,
        private array $probes,
        private array $sections,
        private ConfiguredMessagingSystem $messagingSystem,
        private EcotoneClockInterface $clock,
    ) {
    }

    public function snapshotJson(?string $sourceService): string
    {
        $this->authorizeRequestFrom($sourceService);

        return json_encode($this->snapshot(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{
     *     schemaVersion: int,
     *     serviceName: string,
     *     takenAt: string,
     *     overview: array{ecotoneVersion: string|null, phpVersion: string, framework: string, licence: array{enterprise: bool, expiresAt: string|null}},
     *     flowGraph: array<string, mixed>,
     *     topology: array{serviceMaps: array<mixed>},
     *     channels: list<array{name: string, kind: string, ready: int|null, delayed: int|null, inFlight: int|null, consumers: int|null, probeError: string|null}>,
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
            'channels' => $this->channelsWithDepth(),
        ];

        foreach ($this->sections as $sectionName => $section) {
            $snapshot[$sectionName] = $section->snapshot();
        }

        return $snapshot;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channelsWithDepth(): array
    {
        $namesByGroup = [];
        foreach ($this->channels as $channel) {
            if ($channel['probeGroup'] !== null) {
                $namesByGroup[$channel['probeGroup']][$channel['name']] = $channel['destination'];
            }
        }

        $depths = [];
        foreach ($namesByGroup as $group => $destinationByChannel) {
            $depths = [...$depths, ...$this->probe($this->probes[$group], $destinationByChannel)];
        }

        return array_map(
            fn (array $channel): array => [
                'name' => $channel['name'],
                'kind' => $channel['kind'],
                ...($depths[$channel['name']] ?? PulseChannelDepth::unmeasured())->toArray(),
            ],
            $this->channels
        );
    }

    /**
     * @param array<string, string> $destinationByChannel
     * @return array<string, PulseChannelDepth>
     */
    private function probe(PulseChannelProbe $probe, array $destinationByChannel): array
    {
        $startedAt = hrtime(true);
        try {
            $depths = $probe->depthsOf($destinationByChannel, self::PROBE_BUDGET_IN_MILLISECONDS);
        } catch (Throwable $exception) {
            return array_fill_keys(array_keys($destinationByChannel), PulseChannelDepth::failed($exception->getMessage()));
        }

        $tookInMilliseconds = intdiv(hrtime(true) - $startedAt, 1_000_000);
        if ($tookInMilliseconds > self::PROBE_BUDGET_IN_MILLISECONDS) {
            return array_fill_keys(array_keys($destinationByChannel), PulseChannelDepth::failed('Measuring the channel depth took ' . $tookInMilliseconds . ' ms, over the ' . self::PROBE_BUDGET_IN_MILLISECONDS . ' ms budget, so the result was dropped.'));
        }

        return $depths;
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
