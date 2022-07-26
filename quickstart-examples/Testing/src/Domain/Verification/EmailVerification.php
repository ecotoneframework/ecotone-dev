<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification;

use App\Testing\Domain\User\Email;

final class EmailVerification
{
    public function __construct(private Email $email, private VerificationToken $verificationToken, private bool $isVerified) {}

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function isTokenEqual(VerificationToken $verificationToken): bool
    {
        return $this->verificationToken->equals($verificationToken);
    }

    public function finishVerificationWithSuccess(): self
    {
        return new self($this->email, $this->verificationToken, true);
    }

    public function getVerificationToken(): VerificationToken
    {
        return $this->verificationToken;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }
}