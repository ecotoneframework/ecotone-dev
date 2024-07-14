<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

/**
 * licence Apache-2.0
 */
final class PersonRole
{
    public function __construct(private string $role)
    {

    }

    public function getRole(): string
    {
        return $this->role;
    }
}
