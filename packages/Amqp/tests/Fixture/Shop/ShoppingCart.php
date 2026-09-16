<?php

namespace Test\Ecotone\Amqp\Fixture\Shop;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Gateway\MessagePublisher;
use Ecotone\Messaging\Attribute\MessageConsumer;

/**
 * licence Apache-2.0
 */
class ShoppingCart
{
    private $shoppingCart = [];

    #[CommandHandler('addToBasket')]
    public function requestAddingToBasket(string $productName, MessagePublisher $publisher): void
    {
        $publisher->send($productName);
    }

    #[MessageConsumer(MessagingConfiguration::CONSUMER_ID)]
    public function addToBasket(string $productName): void
    {
        $this->shoppingCart[] = $productName;
    }

    #[QueryHandler('getShoppingCartList')]
    public function getShoppingCartList(): array
    {
        return $this->shoppingCart;
    }
}
