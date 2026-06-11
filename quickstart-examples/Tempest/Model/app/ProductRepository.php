<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App;

use App\Domain\Product;
use Ecotone\Modelling\Attribute\Repository;

interface ProductRepository
{
    #[Repository]
    public function getBy(int $id): Product;

    #[Repository]
    public function findBy(int $id): ?Product;

    #[Repository]
    public function save(Product $product): void;
}
