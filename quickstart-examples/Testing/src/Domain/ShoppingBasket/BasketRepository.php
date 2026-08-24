<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket;

use Ecotone\Api\RelatedAggregate;
use Ecotone\Api\Repository;

interface BasketRepository
{
    #[Repository]
    #[RelatedAggregate(Basket::class)]
    public function save(string $userId, int $currentVersion, array $events): void;
}