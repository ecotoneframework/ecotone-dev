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
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
class Product
{
    #[AggregateIdentifier]
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