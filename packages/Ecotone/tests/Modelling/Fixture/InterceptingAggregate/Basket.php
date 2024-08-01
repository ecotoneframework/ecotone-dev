<?php

namespace Test\Ecotone\Modelling\Fixture\InterceptingAggregate;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\QueryHandler;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class Basket
{
    #[Identifier]
    private string $userId;
    private array $items;

    private function __construct(string $personId, array $items)
    {
        $this->userId = $personId;
        $this->items  = $items;
    }

    #[CommandHandler('basket.create')]
    public static function start(array $command): self
    {
        return new self($command['userId'], []);
    }

    #[CommandHandler('basket.add')]
    public function addToBasket(array $command): void
    {
        $this->items[] = $command['item'];
    }

    #[CommandHandler('basket.delete')]
    public function deleteFromBasket(array $command): void
    {
        $this->items = \array_filter($this->items, fn ($item) => $item !== $command['item']);
    }

    #[QueryHandler('basket.get')]
    public function getBasket(): array
    {
        return $this->items;
    }
}
