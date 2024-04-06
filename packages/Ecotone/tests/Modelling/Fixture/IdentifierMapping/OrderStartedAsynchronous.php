<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\IdentifierMapping;

use Ecotone\Modelling\Attribute\TargetIdentifier;

final class OrderStartedAsynchronous
{
    public function __construct(
        #[TargetIdentifier('orderId')] public string $id
    )
    {

    }
}