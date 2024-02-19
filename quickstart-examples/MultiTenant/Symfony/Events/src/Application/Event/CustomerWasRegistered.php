<?php

declare(strict_types=1);

namespace App\MultiTenant\Application\Event;

final readonly class CustomerWasRegistered
{
    public function __construct(public int $customerId)
    {
    }
}