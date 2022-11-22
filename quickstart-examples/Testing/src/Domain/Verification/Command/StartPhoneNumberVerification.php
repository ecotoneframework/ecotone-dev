<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification\Command;

use App\Testing\Domain\User\PhoneNumber;
use App\Testing\Domain\Verification\VerificationToken;

final class StartPhoneNumberVerification
{
    public function __construct(private PhoneNumber $phoneNumber, private VerificationToken $verificationToken) {}

    public function getPhoneNumber(): PhoneNumber
    {
        return $this->phoneNumber;
    }

    public function getVerificationToken(): VerificationToken
    {
        return $this->verificationToken;
    }
}