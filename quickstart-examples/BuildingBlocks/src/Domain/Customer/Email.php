<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use Assert\Assert;

final readonly class Email
{
    public function __construct(
        private string $email
    ) {
        Assert::that($email)
            ->notEmpty('Email cannot be empty')
            ->email('Email must be valid');
    }

    public function __toString(): string
    {
        return $this->email;
    }
}