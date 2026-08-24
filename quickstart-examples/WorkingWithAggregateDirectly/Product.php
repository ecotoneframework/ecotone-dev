<?php declare(strict_types=1);

namespace App\WorkingWithAggregateDirectly;

use App\WorkingWithAggregateDirectly\Command\ChangePrice;
use App\WorkingWithAggregateDirectly\Command\RegisterProduct;
use App\WorkingWithAggregateDirectly\Event\PriceWasChanged;
use App\WorkingWithAggregateDirectly\Event\ProductWasRegistered;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
class Product
{
    #[Identifier]
    private string $productId;
    use WithAggregateVersioning;

    private float $price;

    #[CommandHandler]
    public static function register(RegisterProduct $command): array
    {
        return [new ProductWasRegistered($command->getProductId(), $command->getPrice())];
    }

    #[CommandHandler]
    public function changePrice(ChangePrice $command): array
    {
        return [new PriceWasChanged($this->productId, $command->getPrice())];
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
}