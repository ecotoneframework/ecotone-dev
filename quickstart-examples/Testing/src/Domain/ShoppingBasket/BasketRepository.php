<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket;

use Ecotone\Modelling\Attribute\RelatedAggregate;
use Ecotone\Modelling\Attribute\Repository;

interface BasketRepository
{
    #[Repository]
    #[RelatedAggregate(Basket::class)]
    public function save(string $userId, int $currentVersion, array $events): void;
}