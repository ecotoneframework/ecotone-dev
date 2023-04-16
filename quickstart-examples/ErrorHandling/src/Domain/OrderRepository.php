<?php

declare(strict_types=1);

namespace App\Domain;

use Ecotone\Modelling\Attribute\Repository;

interface OrderRepository
{
    #[Repository]
    public function get(string $orderId): Order;

    #[Repository]
    public function save(Order $order): void;
}