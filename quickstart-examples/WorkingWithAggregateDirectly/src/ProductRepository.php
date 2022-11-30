<?php

declare(strict_types=1);

namespace App\WorkingWithAggregateDirectly;

use Ecotone\Modelling\Attribute\RelatedAggregate;
use Ecotone\Modelling\Attribute\Repository;

/**
 * Implementation will be auto-generated and this class will be available in your Dependency Container
 */
interface ProductRepository
{
    /** Nullable return type. This will return null, when not null */
    #[Repository]
    public function findBy(string $productId): ?Product;

    /** Non-nullable return type. This will throw exception if not found. */
    #[Repository]
    public function getBy(string $productId): Product;

    #[Repository]
    #[RelatedAggregate(Product::class)]
    public function save(string $productId, int $currentVersion, array $events): void;
}