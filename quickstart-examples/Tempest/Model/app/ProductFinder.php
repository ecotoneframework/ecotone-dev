<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App;

use Ecotone\Dbal\Attribute\DbalQuery;

interface ProductFinder
{
    #[DbalQuery('SELECT id, name, price FROM products ORDER BY id')]
    public function findAll(): array;
}
