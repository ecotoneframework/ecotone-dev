<?php

namespace Test\Ecotone\Messaging\Fixture\Behat\Shopping;

/**
 * Class ToOrderRequestTransformer
 * @package Test\Ecotone\Messaging\Fixture\Behat\Shopping
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class ToReservationRequestTransformer
{
    public function transform(string $bookName): ReserveRequest
    {
        return new ReserveRequest($bookName);
    }
}
