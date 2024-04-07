<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\IdentifierMapping\AttributeMapping;

final class OrderStarted
{
    public function __construct(
        public string $id,
        public string $status = "started"
    )
    {

    }
}