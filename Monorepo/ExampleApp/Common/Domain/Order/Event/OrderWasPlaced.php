<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Order\Event;

use Ramsey\Uuid\UuidInterface;

final class OrderWasPlaced
{
    public function __construct(
        public UuidInterface $orderId
    ) {}
}