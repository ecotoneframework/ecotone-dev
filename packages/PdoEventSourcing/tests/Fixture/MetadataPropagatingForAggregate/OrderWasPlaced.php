<?php

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate;

class OrderWasPlaced
{
    public function __construct(public string $orderId)
    {
    }
}
