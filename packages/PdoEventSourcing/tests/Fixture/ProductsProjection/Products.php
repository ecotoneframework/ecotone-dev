<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProductsProjection;

use Ecotone\Api\EventHandler;
use Ecotone\Api\FromStream;
use Ecotone\Api\Polling;
use Ecotone\Api\Projection;
use Ecotone\Api\QueryHandler;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\ProductWasAddedToBasket;

#[Projection(self::PROJECTION_NAME)]
#[FromStream(Basket::BASKET_STREAM)]
#[Polling(endpointId: self::PROJECTION_NAME)]
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
