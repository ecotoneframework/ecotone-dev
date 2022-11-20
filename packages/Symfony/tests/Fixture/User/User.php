<?php

declare(strict_types=1);

namespace Fixture\User;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifier;

#[Aggregate]
final class User
{
    private function __construct(#[AggregateIdentifier] private string $userId)
    {

    }

    public static function register(string $userId): self
    {
        return new self($userId);
    }
}