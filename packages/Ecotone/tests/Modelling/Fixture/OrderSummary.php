<?php

namespace Test\Ecotone\Modelling\Fixture;

use Ecotone\Api\IgnorePayload;
use Ecotone\Api\QueryHandler;

/**
 * licence Apache-2.0
 */
class OrderSummary
{
    #[QueryHandler]
    #[IgnorePayload]
    public function getOrders(GetOrdersQuery $query): array
    {
        //return orders
    }
}
