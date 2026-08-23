<?php

declare(strict_types=1);

namespace Fixture\User;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Identifier;

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
