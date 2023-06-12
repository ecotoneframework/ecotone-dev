<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Order;

use Ecotone\Modelling\Attribute\Repository;
use Ramsey\Uuid\UuidInterface;

interface OrderRepository
{
    #[Repository]
    public function getBy(UuidInterface $orderId): Order;
}