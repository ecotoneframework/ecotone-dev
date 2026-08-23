<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket;

use Ecotone\Api\Attribute\BusinessMethod;
use Ecotone\Api\Attribute\Identifier;
use Ramsey\Uuid\UuidInterface;

interface UserService
{
    #[BusinessMethod("user.isVerified")]
    public function isUserVerified(#[Identifier] UuidInterface $userId): bool;
}