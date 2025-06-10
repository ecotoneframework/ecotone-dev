<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\IdentifierMapping\AttributeMapping;

final class OrderId
{
    public function __construct(private string $id)
    {

    }

    public function __toString(): string
    {
        return $this->id;
    }
}