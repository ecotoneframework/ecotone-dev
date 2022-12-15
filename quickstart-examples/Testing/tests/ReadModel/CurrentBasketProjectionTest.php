<?php

declare(strict_types=1);

namespace Test\App\ReadModel;

use App\Testing\Domain\ShoppingBasket\Basket;
use App\Testing\Domain\ShoppingBasket\Event\OrderWasPlaced;
use App\Testing\Domain\ShoppingBasket\Event\ProductWasAddedToBasket;
use App\Testing\Domain\ShoppingBasket\Event\ProductWasRemovedFromBasket;
use App\Testing\Infrastructure\Converter\EmailConverter;
use App\Testing\Infrastructure\Converter\PhoneNumberConverter;
use App\Testing\Infrastructure\Converter\UuidConverter;
use App\Testing\ReadModel\CurrentBasketProjection;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CurrentBasketProjectionTest extends TestCase
{
    public function test_listing_basket_products_for_user()
    {
        $userId = Uuid::uuid4();
        $productId = Uuid::uuid4();
        $productPrice = 500;

        /** Verifying projection's read model */
        $this->assertEquals(
            [$productId->toString() => $productPrice],
            $this->getTestSupport()
                ->withEventStream(Basket::class, [
                    new ProductWasAddedToBasket($userId, $productId, $productPrice),
                ])
                ->triggerProjection("current_basket")
                ->sendQueryWithRouting(CurrentBasketProjection::GET_CURRENT_BASKET_QUERY, $userId)
        );
    }

    public function test_listing_basket_when_product_was_removed()
    {
        $userId = Uuid::uuid4();
        $productId = Uuid::uuid4();
        $productPrice = 500;

        $this->assertEquals(
            [],
            $this->getTestSupport()
                ->withEventStream(Basket::class, [
                    new ProductWasAddedToBasket($userId, $productId, $productPrice),
                    new ProductWasRemovedFromBasket($userId, $productId)
                ])
                ->triggerProjection("current_basket")
                ->sendQueryWithRouting(CurrentBasketProjection::GET_CURRENT_BASKET_QUERY, $userId)
        );
    }

    public function test_listing_basket_when_order_was_placed()
    {
        $userId = Uuid::uuid4();
        $productId = Uuid::uuid4();
        $productPrice = 500;

        $this->assertEquals(
            [],
            $this->getTestSupport()
                ->withEventStream(Basket::class, [
                    new ProductWasAddedToBasket($userId, $productId, $productPrice),
                    new OrderWasPlaced($userId, [$productId])
                ])
                ->triggerProjection("current_basket")
                ->sendQueryWithRouting(CurrentBasketProjection::GET_CURRENT_BASKET_QUERY, $userId)
        );
    }

    private function getTestSupport(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            [CurrentBasketProjection::class],
            [new CurrentBasketProjection(), new EmailConverter(), new PhoneNumberConverter(), new UuidConverter()],
            configuration: ServiceConfiguration::createWithDefaults()
                // Loading converters, so they can be used for events
                ->withNamespaces(["App\Testing\Infrastructure\Converter"]),
            pathToRootCatalog: __DIR__, // can be ignored, needed for running inside ecotone-dev monorepo
        );
    }
}