<?php declare(strict_types=1);

namespace App\EventSourcing;

use App\EventSourcing\Command\ChangePrice;
use App\EventSourcing\Command\RegisterProduct;
use App\EventSourcing\Event\PriceWasChanged;
use App\EventSourcing\Event\ProductWasRegistered;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
class Product
{
    #[AggregateIdentifier]
    private int $productId;
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