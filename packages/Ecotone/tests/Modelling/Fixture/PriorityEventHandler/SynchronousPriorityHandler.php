<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\PriorityEventHandler;

use Ecotone\Messaging\Attribute\Endpoint\Priority;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

final class SynchronousPriorityHandler
{
    public array $triggers = [];

    #[Priority(1)]
    #[EventHandler]
    public function lowerPriorityHandler(OrderWasPlaced $event): void
    {
        $this->triggers[] = 'lowerPriorityHandler';
    }

    #[Priority(5)]
    #[EventHandler]
    public function higherPriorityHandler(OrderWasPlaced $event): void
    {
        $this->triggers[] = "higherPriorityHandler";
    }

    #[QueryHandler("getTriggers")]
    public function getTriggers(): array
    {
        return $this->triggers;
    }
}