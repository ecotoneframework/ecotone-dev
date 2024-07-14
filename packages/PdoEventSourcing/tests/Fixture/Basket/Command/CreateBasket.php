<?php

namespace Test\Ecotone\EventSourcing\Fixture\Basket\Command;

/**
 * licence Apache-2.0
 */
class CreateBasket
{
    private string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
