<?php

namespace Test\Ecotone\Amqp\Fixture\Order;

/**
 * licence Apache-2.0
 */
class PlaceOrder
{
    /**
     * @var string
     */
    private $personId;

    /**
     * PlaceOrder constructor.
     * @param string $personId
     */
    public function __construct(string $personId)
    {
        $this->personId = $personId;
    }

    /**
     * @return string
     */
    public function getPersonId(): string
    {
        return $this->personId;
    }
}
