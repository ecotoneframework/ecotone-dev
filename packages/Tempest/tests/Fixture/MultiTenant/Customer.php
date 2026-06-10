<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\MultiTenant;

use Tempest\Database\IsDatabaseModel;
use Tempest\Database\Table;

/**
 * licence Apache-2.0
 */
#[Table('persons')]
final class Customer
{
    use IsDatabaseModel;

    public int $customer_id;
    public string $name;

    public static function register(RegisterCustomer $command): self
    {
        $customer = new self();
        $customer->customer_id = $command->customerId;
        $customer->name = $command->name;

        return $customer;
    }
}
