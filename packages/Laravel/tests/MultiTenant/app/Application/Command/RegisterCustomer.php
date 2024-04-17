<?php

declare(strict_types=1);

namespace App\MultiTenant\Application\Command;

final class RegisterCustomer
{
    public function __construct(public int $customerId, public string $name)
    {
    }
}
