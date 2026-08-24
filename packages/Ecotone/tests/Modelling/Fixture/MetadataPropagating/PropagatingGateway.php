<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\MetadataPropagating;

use Ecotone\Api\Headers;
use Ecotone\Api\MessageGateway;
use Ecotone\Api\PropagateHeaders;

/**
 * licence Apache-2.0
 */
interface PropagatingGateway
{
    #[MessageGateway('placeOrder')]
    public function placeOrderWithPropagation(#[Headers] $headers): void;

    #[MessageGateway('placeOrder')]
    #[PropagateHeaders(false)]
    public function placeOrderWithoutPropagation(#[Headers] $headers): void;
}
