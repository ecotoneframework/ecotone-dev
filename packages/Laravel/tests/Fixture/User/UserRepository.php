<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\User;

use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\Repository;

/**
 * licence Apache-2.0
 */
interface UserRepository
{
    #[Repository]
    public function save(User $user): void;

    #[Repository]
    public function getUser(#[Identifier] $userId): User;
}
