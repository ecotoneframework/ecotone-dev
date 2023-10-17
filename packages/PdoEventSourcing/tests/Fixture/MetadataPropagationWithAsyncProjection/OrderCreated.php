<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent('order.created')]
final class OrderCreated
{
    public function __construct(public int $id)
    {
    }
}
