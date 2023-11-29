<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\Order;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifierMethod;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithEvents;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Rfc4122\UuidV4;
use Test\Ecotone\Laravel\Fixture\Product\OrderPriceCalculator;
use Ramsey\Uuid\Uuid;

#[Aggregate]
final class Order extends Model
{
    // This provides us with possibility to record Events
    use WithEvents;

    public $fillable = ['user_id', 'id', 'product_ids', 'total_price_amount', 'total_price_currency', 'is_cancelled'];

    protected function __construct() {
        parent::__construct();
    }

    #[CommandHandler]
    public static function place(PlaceOrder $command, OrderPriceCalculator $orderPriceCalculator): self
    {
        $price = $orderPriceCalculator->calculateFor($command->productIds);

        $order = self::create([
            'user_id' => $command->userId,
            'product_ids' => \json_encode($command->productIds),
            'total_price_amount' => $price->getAmount(),
            'total_price_currency' => $price->getCurrency()->getCode(),
            'is_cancelled' => false,
            'created_at' => time()
        ]);
        // this will ensure assigning id to the model
        $order->save();
        $order->recordThat(new OrderWasPlaced($order->id, $order->user_id));

        return $order;
    }

    #[AggregateIdentifierMethod('id')]
    public function getId(): int
    {
        return $this->id;
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
