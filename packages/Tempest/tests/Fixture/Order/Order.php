<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Order;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\IdentifierMethod;
use Ecotone\Modelling\Attribute\QueryHandler;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;

/**
 * licence Apache-2.0
 */
#[Aggregate]
final class Order
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    public string $user_id;

    public int $total_price;

    public bool $is_cancelled;

    #[CommandHandler]
    public static function place(PlaceOrder $command): self
    {
        $order = new self();
        $order->user_id = $command->userId;
        $order->total_price = $command->totalPrice;
        $order->is_cancelled = false;
        $order->save();

        return $order;
    }

    #[IdentifierMethod('id')]
    public function getId(): int
    {
        return $this->id->value;
    }

    #[CommandHandler(routingKey: 'cancel_order')]
    public function cancel(): void
    {
        $this->is_cancelled = true;
    }

    #[QueryHandler('is_cancelled')]
    public function isCancelled(): bool
    {
        return $this->is_cancelled;
    }
}
