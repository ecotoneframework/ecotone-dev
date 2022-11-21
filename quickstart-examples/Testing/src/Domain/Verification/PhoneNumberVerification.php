<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification;

use App\Testing\Domain\User\PhoneNumber;

final class PhoneNumberVerification
{
    public function __construct(private PhoneNumber $phoneNumber, private VerificationToken $verificationToken, private bool $isVerified) {}

    public function getPhoneNumber(): PhoneNumber
    {
        return $this->phoneNumber;
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