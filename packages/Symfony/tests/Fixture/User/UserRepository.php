<?php

declare(strict_types=1);

namespace Fixture\User;

use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Repository;

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
