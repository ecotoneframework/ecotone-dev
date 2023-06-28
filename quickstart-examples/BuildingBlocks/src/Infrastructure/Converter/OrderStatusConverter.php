<?php

declare(strict_types=1);

namespace App\Infrastructure\Converter;

use App\Domain\Order\OrderStatus;
use Ecotone\Messaging\Attribute\Converter;

final class OrderStatusConverter
{
    #[Converter]
    public function from(OrderStatus $status): string
    {
        return $status->value;
    }

    #[Converter]
    public function to(string $status): OrderStatus
    {
        return OrderStatus::from($status);
    }
}