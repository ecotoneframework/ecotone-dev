<?php

declare(strict_types=1);

namespace App\Domain\Product;

use App\Domain\Product\Command\ApproveProduct;
use App\Domain\Product\Command\ChangeProductPrice;
use App\Domain\Product\Command\CreateProduct;
use App\Domain\Product\Event\ProductPriceWasChanged;
use App\Domain\Product\Event\ProductWasAdded;
use App\Domain\Product\Event\ProductWasApproved;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithAggregateVersioning;
use Money\Money;
use Ramsey\Uuid\UuidInterface;

/**
 * @link https://docs.ecotone.tech/modelling/command-handling/state-stored-aggregate
 */
#[EventSourcingAggregate]
final class Product
{
    use WithAggregateVersioning;

    #[Identifier]
    private readonly UuidInterface $productId;

    private readonly Money $price;

    #[CommandHandler]
    public static function addProduct(CreateProduct $command): array
    {
        return [
            new ProductWasAdded(
                $command->productId,
                $command->name,
                $command->price
            )
        ];
    }

    #[CommandHandler]
    public function changePrice(ChangeProductPrice $command): array
    {
        return [
            new ProductPriceWasChanged(
                $this->productId,
                $command->price
            )
        ];
    }

    #[CommandHandler("product.approve")]
    public function approve(): array
    {
        return [
            new ProductWasApproved(
                $this->productId
            )
        ];
    }

    #[QueryHandler("product.getPrice")]
    public function getPrice(): Money
    {
        return $this->price;
    }

    #[EventSourcingHandler]
    public function applyProductWasAdded(ProductWasAdded $event): void
    {
        $this->productId = $event->productId;
        $this->price = $event->price;
    }

    public function applyProductPriceWasChanged(ProductPriceWasChanged $event): void
    {
        $this->price = $event->price;
    }
}