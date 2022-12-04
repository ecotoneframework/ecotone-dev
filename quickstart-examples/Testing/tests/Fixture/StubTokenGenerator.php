<?php

declare(strict_types=1);

namespace Test\App\Fixture;

use App\Testing\Domain\Verification\TokenGenerator;
use App\Testing\Domain\Verification\VerificationToken;

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