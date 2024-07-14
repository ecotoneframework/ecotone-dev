<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProductsProjection;

use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\ProductWasAddedToBasket;

#[Projection(self::PROJECTION_NAME, Basket::BASKET_STREAM)]
/**
 * licence Apache-2.0
 */
final class Products
{
    public const PROJECTION_NAME = 'products';
    private array $products = [];

    #[EventHandler(ProductWasAddedToBasket::EVENT_NAME)]
    public function when(ProductWasAddedToBasket $event): void
    {
        if (array_key_exists($event->getProductName(), $this->products)) {
            ++$this->products[$event->getProductName()];
        }
        $this->products[$event->getProductName()] = 1;
    }

    #[QueryHandler('getALlProducts')]
    public function getAllProducts(): array
    {
        return $this->products;
    }
}
