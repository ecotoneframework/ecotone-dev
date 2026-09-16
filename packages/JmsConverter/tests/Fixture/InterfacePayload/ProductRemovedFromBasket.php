<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Fixture\InterfacePayload;

/**
 * licence Apache-2.0
 */
final class ProductRemovedFromBasket implements BasketContentChanged
{
    public function __construct(public string $basketId, public string $productId)
    {
    }

    public function basketId(): string
    {
        return $this->basketId;
    }
}
