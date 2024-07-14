<?php

declare(strict_types=1);

namespace App\MultiTenant\Application\Event;

/**
 * licence Apache-2.0
 */
final class CustomerWasRegistered
{
    public function __construct(public int $customerId)
    {
    }
}
