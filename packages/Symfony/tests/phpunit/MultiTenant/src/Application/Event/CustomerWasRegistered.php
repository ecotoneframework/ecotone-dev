<?php

declare(strict_types=1);

namespace Symfony\App\MultiTenant\Application\Event;

final class CustomerWasRegistered
{
    public function __construct(public int $customerId)
    {
    }
}
