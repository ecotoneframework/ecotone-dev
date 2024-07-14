<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\User;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class User
{
    private function __construct(#[Identifier] private string $userId)
    {
    }

    public static function register(string $userId): self
    {
        return new self($userId);
    }
}
