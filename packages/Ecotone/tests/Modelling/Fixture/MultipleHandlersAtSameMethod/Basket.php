<?php

namespace Test\Ecotone\Modelling\Fixture\MultipleHandlersAtSameMethod;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
class Basket
{
    private array $items;

    #[CommandHandler('basket.add')]
    #[CommandHandler('basket.removeLast')]
    public function addToBasket(array $command): void
    {
        if (! isset($command['item'])) {
            array_pop($this->items);
            return;
        }

        $this->items[] = $command['item'];
    }

    #[QueryHandler('basket.get')]
    #[QueryHandler('basket.getAll')]
    public function getBasket(): array
    {
        return $this->items;
    }
}
