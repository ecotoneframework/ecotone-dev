<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification;

final class VerificationToken
{
    private function __construct(private string $token) {}

    public static function from(string $token): self
    {
        return new self($token);
    }

    public function equals(self $verificationToken): bool
    {
        return $this->token === $verificationToken->token;
    }
}