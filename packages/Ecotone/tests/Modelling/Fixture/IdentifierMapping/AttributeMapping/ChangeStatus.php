<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\IdentifierMapping\AttributeMapping;

final class ChangeStatus
{
    public function __construct(
        public string $orderId,
        public string $status
    ) {

    }
}