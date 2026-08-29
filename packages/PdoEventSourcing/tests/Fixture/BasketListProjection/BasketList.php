<?php

namespace Test\Ecotone\EventSourcing\Fixture\BasketListProjection;

use Ecotone\Api\EventHandler;
use Ecotone\Api\FromStream;
use Ecotone\Api\Polling;
use Ecotone\Api\Projection;
use Ecotone\Api\QueryHandler;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\BasketWasCreated;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\ProductWasAddedToBasket;

#[Projection(self::PROJECTION_NAME)]
#[FromStream(Basket::BASKET_STREAM)]
#[Polling(endpointId: self::PROJECTION_NAME)]
/**
 * licence Apache-2.0
 */
class BasketList
{
    public const PROJECTION_NAME = 'basketList';
    private array $basketsList = [];

    #[EventHandler(BasketWasCreated::EVENT_NAME)]
    public function addBasket(array $event): void
    {
        $this->basketsList[$event['id']] = [];
    }

    #[EventHandler(ProductWasAddedToBasket::EVENT_NAME)]
    public function addProduct(ProductWasAddedToBasket $event): void
    {
        $this->basketsList[$event->getId()][] = $event->getProductName();
    }

    #[QueryHandler('getALlBaskets')]
    public function getAllBaskets(): array
    {
        return $this->basketsList;
    }
}
