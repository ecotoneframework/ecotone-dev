<?php

declare(strict_types=1);

namespace App\Testing\Domain\User;

use Assert\Assert;

final class PhoneNumber
{
    private function __construct(private string $number)
    {
        Assert::that($number)->e164(sprintf("Phone number %s is invalid", $number));
    }

    public static function create(string $number): self
    {
        return new self($number);
    }

    public function toString(): string
    {
        return $this->number;
    }
}