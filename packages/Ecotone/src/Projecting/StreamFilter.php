<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use Ecotone\EventSourcing\EventStore;

/**
 * Value object representing a filter for stream-based projections.
 * Contains stream name, optional aggregate type, and event store reference.
 */
final class StreamFilter
{
    public function __construct(
        public readonly string $streamName,
        public readonly ?string $aggregateType = null,
        public readonly string $eventStoreReferenceName = EventStore::class,
    ) {
    }
}

