<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket;

use App\Testing\Domain\ShoppingBasket\Command\AddProductToBasket;
use App\Testing\Domain\ShoppingBasket\Event\OrderWasPlaced;
use App\Testing\Domain\ShoppingBasket\Event\ProductWasAddedToBasket;
use App\Testing\Domain\ShoppingBasket\Event\ProductWasRemovedFromBasket;
use Assert\Assert;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ramsey\Uuid\UuidInterface;

#[EventSourcingAggregate]
final class Basket
{
    #[AggregateIdentifier]
    private UuidInterface $userId;
    /** @var string[] */
    private array $products = [];

    #[CommandHandler]
    public static function addToNewBasket(AddProductToBasket $command): array
    {
        return [new ProductWasAddedToBasket($command->getUserId(), $command->getProduct())];
    }

    #[CommandHandler]
    public function add(AddProductToBasket $command): array
    {
        if (in_array($command->getProduct(), $this->products)) {
            return [];
        }

        return [new ProductWasAddedToBasket($command->getUserId(), $command->getProduct())];
    }

    #[CommandHandler]
    public function remove(ProductWasRemovedFromBasket $command): array
    {
        if (!in_array($command->getProduct(), $this->products)) {
            return [];
        }

        return [new ProductWasRemovedFromBasket($command->getUserId(), $command->getProduct())];
    }

    #[CommandHandler]
    public function placeOrder(#[Reference] UserService $userService): array
    {
        Assert::that($userService->isUserVerified($this->userId))->true("User must be verified to place order");

        return [new OrderWasPlaced($this->userId, $this->products)];
    }

    #[EventSourcingHandler]
    public function applyProductWasAddedToBasket(ProductWasAddedToBasket $event): void
    {
        $this->userId = $event->getUserId();
        $this->products[] = $event->getProduct();
    }

    #[EventSourcingHandler]
    public function applyProductWasRemovedFromBasket(ProductWasRemovedFromBasket $event): void
    {
        unset($this->products[array_search($event->getProduct(), $this->products)]);
        $this->products = array_values($this->products);
    }
}