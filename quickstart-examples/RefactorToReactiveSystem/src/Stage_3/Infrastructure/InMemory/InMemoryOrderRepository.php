<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Infrastructure\InMemory;

use App\ReactiveSystem\Stage_3\Domain\Order\Order;
use App\ReactiveSystem\Stage_3\Domain\Order\OrderRepository;
use Ramsey\Uuid\UuidInterface;

final class InMemoryOrderRepository implements OrderRepository
{
    /** @var Order[] */
    private array $orders;

    public function __construct() {}

    public function save(Order $order): void
    {
        $this->orders[$order->getOrderId()->toString()] = $order;
    }

    public function getBy(UuidInterface $orderId): Order
    {
        if (!isset($this->orders[$orderId->toString()])) {
            throw new \RuntimeException(sprintf("User with id %s not found", $orderId->toString()));
        }

        return $this->orders[$orderId->toString()];
    }
}