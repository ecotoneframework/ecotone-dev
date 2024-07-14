<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent('order.product_added')]
/**
 * licence Apache-2.0
 */
final class ProductAddedToOrder
{
    public function __construct(public int $id)
    {
    }
}
