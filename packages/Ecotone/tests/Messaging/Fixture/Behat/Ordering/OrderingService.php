<?php

namespace Test\Ecotone\Messaging\Fixture\Behat\Ordering;

use Ecotone\Messaging\Future;

/**
 * Interface OrderingService
 * @package Test\Ecotone\Messaging\Fixture\Behat\Ordering
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface OrderingService
{
    /**
     * @param Order $order
     * @return Future
     */
    public function processOrder(Order $order): Future;
}
