<?php

declare(strict_types=1);

namespace App\Customer;

final class RegisterCustomer
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
    ) {
    }
}
