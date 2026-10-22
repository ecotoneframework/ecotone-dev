<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Pulse\Tracking;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSnapshot;
use Ecotone\Projecting\ProjectionOperations;
use Ecotone\Projecting\ProjectionRegistry;
use Ecotone\Tracing\TracingId;
use JsonException;
use stdClass;

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
final class PulseProjectionActions
{
    public const ROUTING_KEY = 'ecotone.pulse.projections.rebuild';
    public const INITIALIZE_ROUTING_KEY = 'ecotone.pulse.projections.initialize';
    private const EXAMPLE = '{"projection": "orders"}';
    private const TRACING_EXAMPLE = "sendMessage('service', 'ecotone.pulse.projections.rebuild', '{\"projection\": \"orders\"}', metadata: ['tracingId' => '0af7651916cd43dd8448eb211c80319c'])";

    public function __construct(
        private TrackingStore $trackings,
        private ProjectionRegistry $projections,
        private ProjectionOperations $projectionOperations,
        private EcotoneClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function rebuild(string $payload, ?string $sourceService, array $headers, PulseSnapshot $snapshot): string
    {
        $snapshot->authorizeRequestFrom($sourceService);
        $snapshot->tenantOfScopedRequest($headers);
        $projection = $this->knownProjectionOf($this->requestOf($payload, 'rebuild'), 'rebuild');
        $tracingId = $this->tracingIdOf(isset($headers[MessageHeaders::TRACING_ID]) ? (string) $headers[MessageHeaders::TRACING_ID] : null, 'rebuild');
        $mode = $this->projections->get($projection)->rebuildsAsynchronously() ? 'asynchronous' : 'synchronous';

        $this->trackings->withinTransaction(function () use ($projection, $tracingId): void {
            $this->trackings->record(new Tracking(
                $tracingId,
                Tracking::PULSE_CATEGORY,
                Tracking::PROJECTION_REBUILD_TYPE,
                $projection,
                $this->trackings->activeTenant(),
                $this->clock->now()->unixTime()->inMicroseconds(),
            ));
            $this->projectionOperations->rebuildTrackedBy($projection, $tracingId);
        });

        return json_encode([
            ...json_decode($snapshot->snapshotJson($sourceService, $headers), true, flags: JSON_THROW_ON_ERROR),
            'rebuildAction' => ['tracingId' => $tracingId, 'projection' => $projection, 'mode' => $mode],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function initialize(string $payload, ?string $sourceService, array $headers, PulseSnapshot $snapshot): string
    {
        $snapshot->authorizeRequestFrom($sourceService);
        $snapshot->tenantOfScopedRequest($headers);
        $projection = $this->knownProjectionOf($this->requestOf($payload, 'initialization'), 'initialize');
        $this->ensureCanBeInitialized($projection);
        $tracingId = $this->tracingIdOf(isset($headers[MessageHeaders::TRACING_ID]) ? (string) $headers[MessageHeaders::TRACING_ID] : null, 'initialization');

        $this->trackings->withinTransaction(function () use ($projection, $tracingId): void {
            $this->trackings->record(new Tracking(
                $tracingId,
                Tracking::PULSE_CATEGORY,
                Tracking::PROJECTION_INITIALIZATION_TYPE,
                $projection,
                $this->trackings->activeTenant(),
                $this->clock->now()->unixTime()->inMicroseconds(),
            ));
            $this->projectionOperations->initialize($projection);
            $this->projectionOperations->trigger($projection);
        });

        return json_encode([
            ...json_decode($snapshot->snapshotJson($sourceService, $headers), true, flags: JSON_THROW_ON_ERROR),
            'initializeAction' => ['tracingId' => $tracingId, 'projection' => $projection],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function ensureCanBeInitialized(string $projection): void
    {
        $manager = $this->projections->get($projection);
        $alternatives = 'Use Rebuild to run it again from the first event, or Backfill to project the events it has not seen.';
        if ($manager->isPartitioned()) {
            throw InvalidArgumentException::create("Pulse asked to initialize projection {$projection}, but it is partitioned and every partition initializes itself on its first event, so there is no single initialization to run. {$alternatives}");
        }
        if ($manager->isInitialized()) {
            throw InvalidArgumentException::create("Pulse asked to initialize projection {$projection}, but it is already initialized, so there is nothing left to set up. {$alternatives}");
        }
    }

    private function requestOf(string $payload, string $operation): stdClass
    {
        try {
            $request = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $request = null;
        }
        if (! $request instanceof stdClass) {
            throw InvalidArgumentException::create('A Pulse projection ' . $operation . ' request payload must be a JSON object such as ' . self::EXAMPLE . ", but `{$payload}` was sent.");
        }

        return $request;
    }

    private function knownProjectionOf(stdClass $request, string $verb): string
    {
        $projection = $request->projection ?? null;
        if (! is_string($projection) || $projection === '') {
            throw InvalidArgumentException::create('A Pulse projection ' . $verb . ' request needs the name of the `projection` to ' . $verb . ', but the payload had none. Send ' . self::EXAMPLE . ', taking the name from `projections` of the Pulse snapshot.');
        }
        if (! $this->projections->has($projection)) {
            $known = [...$this->projections->projectionNames()];
            sort($known);

            throw InvalidArgumentException::create("Pulse asked to {$verb} projection {$projection}, but there is no such projection. Registered projections: [" . implode(', ', $known) . ']. Send the name given in #[Projection] of the projection to ' . $verb . '.');
        }

        return $projection;
    }

    private function tracingIdOf(?string $tracingId, string $operation): string
    {
        $normalized = $tracingId === null ? null : TracingId::normalizedOrNull($tracingId);
        if ($normalized === null) {
            throw InvalidArgumentException::create('A Pulse projection ' . $operation . ' request needs the `' . MessageHeaders::TRACING_ID . '` header with 32 hexadecimal characters or a UUID, because the ' . $operation . ' is tracked under that trace, but the request had ' . ($tracingId === null ? 'none' : "`{$tracingId}`") . '. Send it as ' . self::TRACING_EXAMPLE . '.');
        }

        return $normalized;
    }
}
