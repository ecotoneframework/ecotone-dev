<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\User;

use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\Repository;

interface UserRepository
{
    #[Repository]
    public function save(User $user): void;

    #[Repository]
    public function getUser(#[Identifier] $userId): User;
}
