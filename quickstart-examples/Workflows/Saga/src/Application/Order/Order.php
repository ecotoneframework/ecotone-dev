<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\Order;

use App\Workflow\Saga\Application\Order\Command\PlaceOrder;
use App\Workflow\Saga\Application\Order\Event\OrderWasPlaced;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithEvents;
use Money\Money;

/**
 * @link https://docs.ecotone.tech/modelling/command-handling/state-stored-aggregate
 */
#[Aggregate]
final class Order
{
    use WithEvents;

    /**
     * @param Item[] $items
     */
    public function __construct(
        #[Identifier] private readonly string $orderId,
        private string $customerId,
        private array $items
    )
    {
        $this->recordThat(new OrderWasPlaced($this->orderId));
    }

    #[CommandHandler]
    public static function place(PlaceOrder $command): self
    {
        return new self($command->orderId, $command->customerId, $command->items);
    }

    /**
     * @link https://docs.ecotone.tech/modelling/command-handling/state-stored-aggregate/aggregate-query-handlers
     */
    #[QueryHandler("order.getTotalPrice")]
    public function getTotalPrice(): Money
    {
        $amount = Money::EUR(0);

        foreach ($this->items as $item) {
            $amount = $amount->add($item->pricePerItem);
        }

        return $amount;
    }
}