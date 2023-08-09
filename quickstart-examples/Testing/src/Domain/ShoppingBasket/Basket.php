<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket;

use App\Testing\Domain\ShoppingBasket\Command\AddProductToBasket;
use App\Testing\Domain\ShoppingBasket\Command\RemoveProductFromBasket;
use App\Testing\Domain\ShoppingBasket\Event\OrderWasPlaced;
use App\Testing\Domain\ShoppingBasket\Event\ProductWasAddedToBasket;
use App\Testing\Domain\ShoppingBasket\Event\ProductWasRemovedFromBasket;
use Assert\Assert;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\WithAggregateVersioning;
use Ramsey\Uuid\UuidInterface;

#[EventSourcingAggregate]
final class Basket
{
    use WithAggregateVersioning;

    #[Identifier]
    private UuidInterface $userId;
    /** @var UuidInterface[] */
    private array $productIds = [];

    #[CommandHandler]
    public static function addToNewBasket(AddProductToBasket $command, ProductService $productService): array
    {
        return [new ProductWasAddedToBasket(
            $command->getUserId(),
            $command->getProductId(),
            $productService->getPrice($command->getProductId())
        )];
    }

    #[CommandHandler]
    public function add(AddProductToBasket $command, ProductService $productService): array
    {
        if (in_array($command->getProductId(), $this->productIds)) {
            return [];
        }

        return [new ProductWasAddedToBasket($command->getUserId(), $command->getProductId(), $productService->getPrice($command->getProductId()))];
    }

    #[CommandHandler]
    public function remove(RemoveProductFromBasket $command): array
    {
        if (!in_array($command->getProductId(), $this->productIds)) {
            return [];
        }

        return [new ProductWasRemovedFromBasket($command->getUserId(), $command->getProductId())];
    }

    #[CommandHandler("order.placeOrder")]
    public function placeOrder(#[Reference] UserService $userService): array
    {
        Assert::that($userService->isUserVerified($this->userId))->true("User must be verified to place order");

        return [new OrderWasPlaced($this->userId, $this->productIds)];
    }

    #[EventSourcingHandler]
    public function applyProductWasAddedToBasket(ProductWasAddedToBasket $event): void
    {
        $this->userId = $event->getUserId();
        $this->productIds[] = $event->getProductId();
    }

    #[EventSourcingHandler]
    public function applyProductWasRemovedFromBasket(ProductWasRemovedFromBasket $event): void
    {
        unset($this->productIds[array_search($event->getProductId(), $this->productIds)]);
        $this->productIds = array_values($this->productIds);
    }
}