<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use Assert\Assert;

final readonly class FullName
{
    public function __construct(private string $fullName)
    {
        Assert::that($fullName)
            ->notEmpty('Full name cannot be empty')
            ->minLength(3, 'Full name must be at least 3 characters long');
    }

    public function __toString(): string
    {
        return $this->fullName;
    }
}