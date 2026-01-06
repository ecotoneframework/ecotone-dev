<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Attribute;

use Attribute;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;

/*
 * Configures a projection to read from an aggregate's event stream.
 * Automatically reads Stream and AggregateType attributes from the aggregate class.
 *
 * This simplifies projection configuration by avoiding duplication of stream
 * and aggregate type configuration that is already defined on the aggregate.
 *
 * Example usage:
 * ```php
 * #[ProjectionV2('order_list')]
 * #[AggregateStream(Order::class)]
 * class OrderListProjection { ... }
 * ```
 *
 * licence Enterprise
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class FromAggregateStream
{
    /**
     * @param class-string $aggregateClass The aggregate class to read Stream and AggregateType from.
     *                                      Must be an EventSourcingAggregate.
     * @param string $eventStoreReferenceName Reference name for the event store
     */
    public function __construct(
        public readonly string $aggregateClass,
        public readonly string $eventStoreReferenceName = EventStore::class
    ) {
    }
}
