<?php declare(strict_types=1);
/*
 * licence Apache-2.0
 */
namespace Monorepo\ExampleAppEventSourcing\ProophProjection;

use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Monorepo\ExampleAppEventSourcing\Common\Event\PriceWasChanged;
use Monorepo\ExampleAppEventSourcing\Common\Event\ProductWasRegistered;
use Monorepo\ExampleAppEventSourcing\Common\PriceChange;
use Monorepo\ExampleAppEventSourcing\Common\Product;

#[\Ecotone\EventSourcing\Attribute\Projection(self::NAME, Product::class)]
class PriceChangeOverTimeProjection
{
    public const NAME = "price_change_over_time";

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

    #[ProjectionDelete]
    public function deleteProjection(): void
    {
        $this->priceChangeOverTime = [];
    }
}