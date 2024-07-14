<?php

namespace Test\Ecotone\Modelling\Fixture\DistributedCommandHandler;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Distributed;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
class ShoppingCenter
{
    public const COUNT_BOUGHT_GOODS = 'countBoughtGoods';
    public const SHOPPING_BUY       = 'shopping.buy';
    private array $boughtGoods = [];

    #[Distributed]
    #[CommandHandler(self::SHOPPING_BUY)]
    public function buy(string $order): void
    {
        $this->boughtGoods[] = $order;
    }

    #[QueryHandler(self::COUNT_BOUGHT_GOODS)]
    public function countBoughtGood(): int
    {
        return count($this->boughtGoods);
    }
}
