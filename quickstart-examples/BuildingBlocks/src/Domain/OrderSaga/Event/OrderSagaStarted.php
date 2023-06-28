<?php

declare(strict_types=1);

namespace App\Domain\OrderSaga\Event;

use Ramsey\Uuid\UuidInterface;

final class OrderSagaStarted
{
    public function __construct(
        public UuidInterface $orderId,
    )
    {}
}