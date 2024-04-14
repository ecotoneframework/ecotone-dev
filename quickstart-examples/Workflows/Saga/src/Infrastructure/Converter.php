<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Infrastructure;

use App\Workflow\Saga\Application\OrderProcess\OrderProcessStatus;

final readonly class Converter
{
    #[\Ecotone\Messaging\Attribute\Converter]
    public function fromStatus(OrderProcessStatus $status): string
    {
        return $status->value;
    }

    #[\Ecotone\Messaging\Attribute\Converter]
    public function toStatus(string $status): OrderProcessStatus
    {
        return OrderProcessStatus::from($status);
    }
}