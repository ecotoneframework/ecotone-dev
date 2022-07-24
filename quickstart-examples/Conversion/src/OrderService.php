<?php

namespace App\Conversion;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Gateway\Converter\Serializer;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

class OrderService
{
    const PLACE_ORDER = "order.place";
    const GET_ORDER = "order.get";

    private array $orders;

    #[CommandHandler(self::PLACE_ORDER)]
    public function placeOrder(PlaceOrder $command) : void
    {
        $this->orders[$command->orderId] = $command->productIds;
    }

    #[QueryHandler(self::GET_ORDER)]
    public function getOrder(GetOrder $query) : array
    {
         if (!array_key_exists($query->orderId, $this->orders)) {
             throw new \InvalidArgumentException("Order was not found " . $query->orderId);
         }

         return $this->orders[$query->orderId];
    }
}