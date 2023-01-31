<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_1\Domain\Order;

interface OrderRepository
{
    public function save(Order $order): void;
}