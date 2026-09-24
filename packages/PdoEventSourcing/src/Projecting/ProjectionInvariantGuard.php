<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

/**
 * Built once at bootstrap from the projection -> stream filter map (§4.6): for a given stream, which event names
 * a partitioned or aggregate-stream-scoped projection subscribes to and would therefore never see if that event
 * carried no aggregate metadata. `'*'` means the projection subscribes to every event on that stream.
 *
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
