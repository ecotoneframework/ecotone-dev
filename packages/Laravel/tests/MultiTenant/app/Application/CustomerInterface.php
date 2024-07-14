<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Ecotone\Dbal\Attribute\DbalWrite;

/**
 * licence Apache-2.0
 */
interface CustomerInterface
{
    #[DbalWrite('INSERT INTO persons (customer_id, name) VALUES (:customerId, :name)')]
    public function register(int $customerId, string $name): void;
}
