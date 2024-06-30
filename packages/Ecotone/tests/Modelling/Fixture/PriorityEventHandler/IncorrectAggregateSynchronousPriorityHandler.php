<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\PriorityEventHandler;

use Ecotone\Messaging\Attribute\Endpoint\Priority;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithEvents;

#[Aggregate]
final class IncorrectAggregateSynchronousPriorityHandler
{
    use WithEvents;

    #[Identifier]
    private int $id;

    private function __construct(int $id)
    {
        $this->id = $id;
        $this->recordThat(new OrderWasPlaced($id));
    }

    #[CommandHandler('setup')]
    public static function setup(int $identifier): self
    {
        return new self($identifier);
    }

    #[Priority(2)]
    #[EventHandler]
    public function lowerPriorityHandler(OrderWasPlaced $event): void
    {
    }

    #[Priority(5)]
    #[EventHandler]
    public function higherPriorityHandler(OrderWasPlaced $event): void
    {
    }
}