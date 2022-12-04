<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification\Command;

use App\Testing\Domain\Verification\VerificationToken;
use Ramsey\Uuid\UuidInterface;

final class VerifyEmail
{
    public function __construct(private UuidInterface $userId, private VerificationToken $verificationToken) {}

    public function getVerificationToken(): VerificationToken
    {
        return $this->verificationToken;
    }
}