<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

/**
 * licence Enterprise
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
