<?php

namespace App\Microservices\Receiver;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\Distributed;
use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryHandler;

class OrderServiceReceiver
{
    const COMMAND_HANDLER_ROUTING = "placeOrder";
    const GET_ALL_ORDERED_PRODUCTS      = "getTicketsCount";

    /** @var string[] */
    private array $orders = [];

    #[Distributed]
    #[CommandHandler(self::COMMAND_HANDLER_ROUTING)]
    public function placeOrder(PlaceOrder $command): void
    {
        $this->orders[$command->getPersonId()] = $command->getProducts();
    }

    #[Distributed]
    #[EventHandler("user.was_banned")]
    public function removeOrders(UserAccountWasBanned $event): void
    {
        unset($this->orders[$event->getPersonId()]);
    }

    #[QueryHandler(self::GET_ALL_ORDERED_PRODUCTS)]
    public function getAllOrderedProducts(): array
    {
        return $this->orders;
    }
}