<?php

namespace Test\Ecotone\EventSourcing\Fixture\BasketListProjection;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Polling;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
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
