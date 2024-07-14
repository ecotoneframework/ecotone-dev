<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\OrderAggregate;

/**
 * licence Apache-2.0
 */
final class OrderCancelled
{
    /**
     * @var string
     */
    private $orderId;

    public function __construct(string $orderId)
    {
        $this->orderId = $orderId;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }
}
