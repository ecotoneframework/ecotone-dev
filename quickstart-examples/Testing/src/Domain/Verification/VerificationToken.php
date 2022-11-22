<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification;

use Ramsey\Uuid\Uuid;

final class VerificationToken
{
    private function __construct(private string $token) {}

    public static function generate(): self
    {
        return new self(Uuid::uuid4()->toString());
    }

    public function equals(self $verificationToken): bool
    {
        return $this->token === $verificationToken->token;
    }
}