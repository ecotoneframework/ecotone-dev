<?php declare(strict_types=1);

namespace Monorepo\ExampleAppEventSourcing\Common;

use Monorepo\ExampleAppEventSourcing\Common\Event\PriceWasChanged;
use Monorepo\ExampleAppEventSourcing\Common\Event\ProductWasRegistered;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

#[Projection("price_change_over_time", Product::class)]
class PriceChangeOverTimeProjection
{
    /** @var PriceChange[][] */
    private array $priceChangeOverTime = [];

    /**
     * @return PriceChange[]
     */
    #[QueryHandler("product.getPriceChange")]
    public function getPriceChangesFor(string $productId): array
    {
        if (!isset($this->priceChangeOverTime[$productId])) {
            return [];
        }

        return $this->priceChangeOverTime[$productId];
    }

    #[EventHandler]
    public function registerPrice(ProductWasRegistered $event): void
    {
        $this->priceChangeOverTime[$event->getProductId()][] = new PriceChange($event->getPrice(), 0);
    }

    #[EventHandler]
    public function registerPriceChange(PriceWasChanged $event): void
    {
        $lastPrice = end($this->priceChangeOverTime[$event->getProductId()]);
        $this->priceChangeOverTime[$event->getProductId()][] = new PriceChange($event->getPrice(), $event->getPrice() - $lastPrice->getPrice());
    }
}