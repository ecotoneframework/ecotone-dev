<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification;

final class StubTokenGenerator implements TokenGenerator
{
    public function __construct(private array $tokens)
    {

    }

    public function generate(): VerificationToken
    {
        $token = array_shift($this->tokens);

        return VerificationToken::from($token);
    }
}