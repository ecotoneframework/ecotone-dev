<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket;

use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Modelling\Attribute\Identifier;
use Ramsey\Uuid\UuidInterface;

interface UserService
{
    #[MessageGateway("user.isVerified")]
    public function isUserVerified(#[Identifier] UuidInterface $userId): bool;
}