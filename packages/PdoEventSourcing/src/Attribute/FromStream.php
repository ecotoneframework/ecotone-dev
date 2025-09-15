<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Attribute;

use Attribute;
use Ecotone\EventSourcing\EventStore;

#[Attribute(Attribute::TARGET_CLASS)]
class FromStream
{
    public function __construct(
        public readonly string $stream,
        public readonly ?string $aggregateType = null,
        public readonly string $eventStoreReferenceName = EventStore::class
    ) {
    }
}
