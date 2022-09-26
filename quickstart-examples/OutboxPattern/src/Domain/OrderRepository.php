<?php

namespace App\OutboxPattern\Domain;

use Ecotone\Modelling\Attribute\Repository;

interface OrderRepository
{
    #[Repository]
    public function save(Order $order): void;

    #[Repository]
    public function findBy(string $orderId): ?Order;
}