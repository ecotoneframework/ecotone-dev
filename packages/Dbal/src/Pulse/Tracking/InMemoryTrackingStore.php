<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Pulse\Tracking;

use Closure;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Interop\Queue\ConnectionFactory;
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
final class InMemoryTrackingStore implements TrackingStore
{
    /** @var array<string, Tracking> */
    private array $trackings = [];

    public function __construct(private ConnectionFactory $connectionFactory)
    {
    }

    public function record(Tracking $tracking): void
    {
        if (isset($this->trackings[$tracking->tracingId])) {
            throw InvalidArgumentException::create(TrackingAlreadyRecorded::message($tracking->tracingId));
        }

        $this->trackings[$tracking->tracingId] = $tracking;
    }

    public function startedSince(int $sinceInMicroseconds, ?string $category, ?string $type, ?string $reference, int $limit): array
    {
        $tenant = $this->activeTenant();
        $matching = array_filter($this->trackings, fn (Tracking $tracking): bool => $tracking->startedAtInMicroseconds >= $sinceInMicroseconds
            && $tracking->tenant === $tenant
            && ($category === null || $tracking->category === $category)
            && ($type === null || $tracking->type === $type)
            && ($reference === null || $tracking->reference === $reference));
        usort($matching, fn (Tracking $left, Tracking $right): int => [$right->startedAtInMicroseconds, $right->tracingId] <=> [$left->startedAtInMicroseconds, $left->tracingId]);

        return array_slice($matching, 0, $limit);
    }

    public function activeTenant(): ?string
    {
        return $this->connectionFactory instanceof MultiTenantConnectionFactory && $this->connectionFactory->hasActiveTenant() ? $this->connectionFactory->currentActiveTenant() : null;
    }

    public function withinTransaction(Closure $work): mixed
    {
        $before = $this->trackings;
        try {
            return $work();
        } catch (Throwable $failure) {
            $this->trackings = $before;

            throw $failure;
        }
    }
}
