<?php declare(strict_types=1);

namespace Monorepo\ExampleAppEventSourcing\Common;

use Monorepo\ExampleAppEventSourcing\Common\Command\ChangePrice;
use Monorepo\ExampleAppEventSourcing\Common\Command\RegisterProduct;
use Monorepo\ExampleAppEventSourcing\Common\Event\PriceWasChanged;
use Monorepo\ExampleAppEventSourcing\Common\Event\ProductWasRegistered;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
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