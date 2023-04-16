<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Domain\Order;

use Ecotone\Modelling\Attribute\Repository;
use Ramsey\Uuid\UuidInterface;

interface OrderRepository
{
    #[Repository]
    public function getBy(UuidInterface $orderId): Order;
}