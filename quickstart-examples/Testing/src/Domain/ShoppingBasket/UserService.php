<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket;

use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ramsey\Uuid\UuidInterface;

interface UserService
{
    #[MessageGateway("user.isVerified")]
    public function isUserVerified(#[AggregateIdentifier] UuidInterface $userId): bool;
}