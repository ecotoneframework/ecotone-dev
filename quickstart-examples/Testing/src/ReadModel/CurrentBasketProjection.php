<?php

declare(strict_types=1);

namespace App\Testing\ReadModel;

use App\Testing\Domain\ShoppingBasket\Basket;
use App\Testing\Domain\ShoppingBasket\Event\OrderWasPlaced;
use App\Testing\Domain\ShoppingBasket\Event\ProductWasAddedToBasket;
use App\Testing\Domain\ShoppingBasket\Event\ProductWasRemovedFromBasket;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Ecotone\Modelling\Attribute\EventHandler;

#[Projection("current_basket", Basket::class)]
final class CurrentBasketProjection
{
    const COLLECTION = "current_basket";

    #[EventHandler]
    public function whenProductWasAddedToBasket(ProductWasAddedToBasket $event, DocumentStore $documentStore): void
    {

        $documentStore->upsertDocument(self::COLLECTION, $event->getUserId()->toString(), []);
    }

    #[EventHandler]
    public function whenProductWasRemovedFromBasket(ProductWasRemovedFromBasket $event): void
    {

    }

    #[EventHandler]
    public function whenOrderWasPlaced(OrderWasPlaced $event): void
    {

    }
}