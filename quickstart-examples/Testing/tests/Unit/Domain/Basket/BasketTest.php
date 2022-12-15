<?php

declare(strict_types=1);

namespace Test\App\Unit\Domain\ShoppingBasket;

use App\Testing\Domain\ShoppingBasket\Basket;
use App\Testing\Domain\ShoppingBasket\BasketRepository;
use App\Testing\Domain\ShoppingBasket\Command\AddProductToBasket;
use App\Testing\Domain\ShoppingBasket\Command\RemoveProductFromBasket;
use App\Testing\Domain\ShoppingBasket\Event\ProductWasAddedToBasket;
use App\Testing\Domain\ShoppingBasket\Event\ProductWasRemovedFromBasket;
use App\Testing\Domain\ShoppingBasket\ProductService;
use App\Testing\Infrastructure\Converter\UuidConverter;
use Ecotone\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\App\Fixture\StubProductService;

final class BasketTest extends TestCase
{
    public function test_adding_product_to_basket()
    {
        $userId = Uuid::uuid4();
        $productId = Uuid::uuid4();
        $productService = new StubProductService(500);

        /** Verifying published events by aggregate, after calling command */
        $this->assertEquals(
            [new ProductWasAddedToBasket($userId, $productId, 500)],
            EcotoneLite::bootstrapFlowTesting([Basket::class], [ProductService::class => $productService])
                ->sendCommand(new AddProductToBasket($userId, $productId))
                ->getRecordedEvents()
        );
    }

    public function test_skipping_product_if_already_added()
    {
        $userId = Uuid::uuid4();
        $productId = Uuid::uuid4();
        $productService = new StubProductService(500);

        $this->assertEquals(
            [],
            EcotoneLite::bootstrapFlowTesting([Basket::class], [ProductService::class => $productService])
                ->sendCommand(new AddProductToBasket($userId, $productId))
                ->discardRecordedMessages()
                ->sendCommand(new AddProductToBasket($userId, $productId))
                ->getRecordedEvents()
        );
    }

    public function test_removing_product_from_basket()
    {
        $userId = Uuid::uuid4();
        $productId = Uuid::uuid4();
        $productService = new StubProductService(500);

        $this->assertEquals(
            [new ProductWasRemovedFromBasket($userId, $productId)],
            EcotoneLite::bootstrapFlowTesting([Basket::class], [ProductService::class => $productService])
                ->sendCommand(new AddProductToBasket($userId, $productId))
                ->discardRecordedMessages()
                ->sendCommand(new RemoveProductFromBasket($userId, $productId))
                ->getRecordedEvents()
        );
    }

    public function test_removing_product_from_basket_with_providing_state()
    {
        /**
         * When using Flow Testing with Event Store, we need to provide Converters for the events.
         */
        $testSupport = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [Basket::class, BasketRepository::class, UuidConverter::class],
            [new UuidConverter()],
            pathToRootCatalog: __DIR__, // can be ignored, needed for running inside ecotone-dev monorepo
        );

        $userId = Uuid::uuid4();
        $productId = Uuid::uuid4();
        $productPrice = 500;

        $basketRepository = $testSupport->getGateway(BasketRepository::class);
        $basketRepository->save($userId->toString(), 0, [
            new ProductWasAddedToBasket($userId, $productId, $productPrice)
        ]);

        $this->assertEquals(
            [new ProductWasRemovedFromBasket($userId, $productId)],
            $testSupport
                ->discardRecordedMessages()
                ->sendCommand(new RemoveProductFromBasket($userId, $productId))
                ->getRecordedEvents()
        );
    }

    public function test_removing_product_that_was_not_registered_from_basket()
    {
        $userId = Uuid::uuid4();
        $productId = Uuid::uuid4();
        $productService = new StubProductService(500);

        $this->assertEquals(
            [],
            EcotoneLite::bootstrapFlowTesting([Basket::class], [ProductService::class => $productService])
                ->sendCommand(new AddProductToBasket($userId, $productId))
                ->discardRecordedMessages()
                ->sendCommand(new RemoveProductFromBasket($userId, Uuid::uuid4()))
                ->getRecordedEvents()
        );
    }
}