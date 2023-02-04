<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Domain\Order;

use Ramsey\Uuid\UuidInterface;

interface OrderRepository
{
    public function save(Order $order): void;

    public function getBy(UuidInterface $orderId): Order;
}