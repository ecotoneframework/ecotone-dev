<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification;

interface TokenGenerator
{
    public function generate(): VerificationToken;
}