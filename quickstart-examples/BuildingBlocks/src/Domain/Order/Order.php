<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Order\Command\PlaceOrder;
use App\Domain\Product\ProductService;
use Assert\Assert;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithAggregateEvents;
use Money\Money;
use Ramsey\Uuid\UuidInterface;

/**
 * @link https://docs.ecotone.tech/modelling/command-handling/state-stored-aggregate
 */
#[Aggregate]
final class Order
{
    use WithAggregateEvents;

    /**
     * @param UuidInterface[] $productIds
     */
    private function __construct(
        #[AggregateIdentifier] private UuidInterface $orderId,
        private UuidInterface $customerId,
        private array $productIds,
        private Money $totalPrice,
        private OrderStatus $status
    ) {
        Assert::that($productIds)->notEmpty("Order must have at least one product");

        $this->recordThat(new Event\OrderWasPlaced($this->orderId, $this->productIds));
    }

    #[CommandHandler]
    public static function place(PlaceOrder $placeOrder, #[Header("executorId")] UuidInterface $customerId, ProductService $productService): self
    {
        $totalPrice = Money::EUR(0);
        foreach ($placeOrder->productIds as $productId) {
            $totalPrice = $totalPrice->add($productService->getPrice($productId));
        }

        return new self(
            $placeOrder->orderId,
            $customerId,
            $placeOrder->productIds,
            $totalPrice,
            OrderStatus::PLACED
        );
    }

    #[CommandHandler("order.cancel")]
    public function cancel(): void
    {
        $this->status = OrderStatus::CANCELLED;

        $this->recordThat(new Event\OrderWasCancelled($this->orderId));
    }

    #[CommandHandler("order.complete")]
    public function complete(): void
    {
        $this->status = OrderStatus::COMPLETED;
    }

    public function getTotalPrice(): Money
    {
        return $this->totalPrice;
    }

    #[QueryHandler("order.get_status")]
    public function getStatus(): OrderStatus
    {
        return $this->status;
    }
}