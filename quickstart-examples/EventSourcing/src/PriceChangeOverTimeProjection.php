<?php declare(strict_types=1);

namespace App\EventSourcing;

use App\EventSourcing\Event\PriceWasChanged;
use App\EventSourcing\Event\ProductWasRegistered;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;

#[Projection("price_change_over_time")]
#[FromAggregateStream(Product::class)]
class PriceChangeOverTimeProjection
{
    /** @var PriceChange[][] */
    private array $priceChangeOverTime = [];

    /**
     * @return PriceChange[]
     */
    #[QueryHandler("product.getPriceChange")]
    public function getPriceChangesFor(int $productId): array
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