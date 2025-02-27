<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace EventSourcing\Fixture\Projecting;

class OrderCreated
{
    public function __construct(
        public string $orderId,
        public string $orderName
    ) {
    }
}