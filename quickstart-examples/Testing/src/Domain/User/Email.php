<?php

declare(strict_types=1);

namespace App\Testing\Domain\User;

use Assert\Assert;

final class Email
{
    private function __construct(private string $address)
    {
        Assert::that($address)->email(sprintf("Email address %s is invalid", $address));
    }

    public static function create(string $address): self
    {
        return new self($address);
    }

    public function toString(): string
    {
        return $this->address;
    }
}