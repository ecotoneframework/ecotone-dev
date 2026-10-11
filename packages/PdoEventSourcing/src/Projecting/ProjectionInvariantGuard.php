<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

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
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class ProjectionInvariantGuard
{
    /**
     * @param array<string, array<string, string>> $guardedEventNamesByStream stream name => (event name or '*' => projection name)
     */
    public function __construct(
        private array $guardedEventNamesByStream,
    ) {
    }

    public function projectionGuardingAggregatelessEvent(string $streamName, string $eventName): ?string
    {
        $guard = $this->guardedEventNamesByStream[$streamName] ?? null;
        if ($guard === null) {
            return null;
        }

        return $guard[$eventName] ?? $guard['*'] ?? null;
    }
}
