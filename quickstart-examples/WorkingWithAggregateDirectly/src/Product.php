<?php declare(strict_types=1);

namespace App\WorkingWithAggregateDirectly;

use App\WorkingWithAggregateDirectly\Command\ChangePrice;
use App\WorkingWithAggregateDirectly\Command\RegisterProduct;
use App\WorkingWithAggregateDirectly\Event\PriceWasChanged;
use App\WorkingWithAggregateDirectly\Event\ProductWasRegistered;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
class Product
{
    const PRODUCT_GET_PRICE_API = "product.getPrice";
    const PRODUCT_CHANGE_PRICE_API = "product.changePrice";
    const PRODUCT_REGISTER_API = "product.register";

    #[AggregateIdentifier]
    private int $productId;
    use WithAggregateVersioning;

    private float $price;

    #[CommandHandler(self::PRODUCT_REGISTER_API)]
    #[CommandHandler]
    public static function register(RegisterProduct $command): array
    {
        return [new ProductWasRegistered($command->getProductId(), $command->getPrice())];
    }

    #[CommandHandler(self::PRODUCT_CHANGE_PRICE_API)]
    #[CommandHandler]
    public function changePrice(ChangePrice $command): array
    {
        return [new PriceWasChanged($this->productId, $command->getPrice())];
    }

    #[QueryHandler(self::PRODUCT_GET_PRICE_API)]
    public function getPrice(): float
    {
        return $this->price;
    }

    #[EventSourcingHandler]
    public function onProductWasRegistered(ProductWasRegistered $event): void
    {
        $this->productId = $event->getProductId();
        $this->price = $event->getPrice();
    }

    #[EventSourcingHandler]
    public function onPriceWasChanged(PriceWasChanged $event): void
    {
        $this->price = $event->getPrice();
    }

    public function getCurrentVersion(): int
    {
        return $this->version;
    }
}