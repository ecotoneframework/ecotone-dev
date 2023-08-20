<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket;

use Ecotone\Messaging\Attribute\BusinessMethod;
use Ecotone\Modelling\Attribute\Identifier;
use Ramsey\Uuid\UuidInterface;

interface UserService
{
    #[BusinessMethod("user.isVerified")]
    public function isUserVerified(#[Identifier] UuidInterface $userId): bool;
}