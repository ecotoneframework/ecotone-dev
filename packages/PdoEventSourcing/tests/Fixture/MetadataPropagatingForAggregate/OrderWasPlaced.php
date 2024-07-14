<?php

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate;

/**
 * licence Apache-2.0
 */
class OrderWasPlaced
{
    public function __construct(public string $orderId)
    {
    }
}
