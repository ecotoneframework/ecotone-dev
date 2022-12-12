<?php

declare(strict_types=1);

namespace App\Testing\ReadModel;

use App\Testing\Domain\Product\Product;
use App\Testing\Domain\ShoppingBasket\Basket;
use App\Testing\Domain\ShoppingBasket\Event\OrderWasPlaced;
use App\Testing\Domain\ShoppingBasket\Event\ProductWasAddedToBasket;
use App\Testing\Domain\ShoppingBasket\Event\ProductWasRemovedFromBasket;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ramsey\Uuid\UuidInterface;

#[Projection("current_basket", [Basket::class, Product::class])]
final class CurrentBasketProjection
{
    private const BASKET_COLLECTION = "current_basket";
    const GET_CURRENT_BASKET_QUERY = "get_current_basket";

    #[QueryHandler(self::GET_CURRENT_BASKET_QUERY)]
    public function getCurrentBasket(UuidInterface $userId, DocumentStore $documentStore): array
    {
        return $documentStore->getDocument(self::BASKET_COLLECTION, $userId->toString());
    }

    #[EventHandler]
    public function whenProductWasAddedToBasket(ProductWasAddedToBasket $event, DocumentStore $documentStore): void
    {
        $basket = $documentStore->findDocument(self::BASKET_COLLECTION, $event->getUserId()->toString());

        if ($basket === null) {
            $basket = [];
        }

        $basket[$event->getProductId()->toString()] = $event->getPrice();

        $documentStore->upsertDocument(
            self::BASKET_COLLECTION,
            $event->getUserId()->toString(),
            $basket
        );
    }

    #[EventHandler]
    public function whenProductWasRemovedFromBasket(ProductWasRemovedFromBasket $event, DocumentStore $documentStore): void
    {
        $basket = $documentStore->getDocument(self::BASKET_COLLECTION, $event->getUserId()->toString());

        unset($basket[$event->getProductId()->toString()]);

        $documentStore->updateDocument(self::BASKET_COLLECTION, $event->getUserId()->toString(), $basket);
    }

    #[EventHandler]
    public function whenOrderWasPlaced(OrderWasPlaced $event, DocumentStore $documentStore): void
    {
        $documentStore->updateDocument(self::BASKET_COLLECTION, $event->getUserId()->toString(), []);
    }
}