<?php

declare(strict_types=1);

namespace App\Testing\Domain\Product;

use App\Testing\Domain\Product\Command\AddProduct;
use App\Testing\Domain\Product\Event\ProductWasAdded;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithAggregateVersioning;
use Ramsey\Uuid\UuidInterface;

#[EventSourcingAggregate]
final class Product
{
    use WithAggregateVersioning;

    #[AggregateIdentifier]
    private UuidInterface $productId;
    private int $price;

    #[CommandHandler]
    public static function add(AddProduct $command): array
    {
        return [new ProductWasAdded(
            $command->getProductId(),
            $command->getName(),
            $command->getPrice()
        )];
    }

    #[QueryHandler("product.getPrice")]
    public function getPrice(): int
    {
        return $this->price;
    }
    #[EventSourcingHandler]
    public function applyProductWasRegistered(ProductWasAdded $event): void
    {
        $this->productId = $event->getProductId();
        $this->price = $event->getPrice();
    }
}