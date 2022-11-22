<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification\Command;

use App\Testing\Domain\User\Email;
use App\Testing\Domain\Verification\VerificationToken;

final class StartEmailVerification
{
    public function __construct(private Email $email, private VerificationToken $verificationToken) {}

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getVerificationToken(): VerificationToken
    {
        return $this->verificationToken;
    }
}