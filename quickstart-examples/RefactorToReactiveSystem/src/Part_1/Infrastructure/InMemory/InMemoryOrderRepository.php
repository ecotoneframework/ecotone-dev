<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_1\Infrastructure\InMemory;

use App\ReactiveSystem\Part_1\Domain\Order\Order;
use App\ReactiveSystem\Part_1\Domain\Order\OrderRepository;

final class InMemoryOrderRepository implements OrderRepository
{
    /** @var Order[] */
    private array $orders;

    public static function createEmpty(): self
    {
        return new self();
    }

    public function save(Order $order): void
    {
        $this->orders[$order->getOrderId()->toString()] = $order;
    }
}