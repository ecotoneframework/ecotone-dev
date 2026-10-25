<?php

declare(strict_types=1);

namespace App\Customer;

use Ecotone\Api\Attribute\QueryHandler;

final class CustomerDirectory
{
    #[QueryHandler('customers.list')]
    public function list(): array
    {
        return Customer::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Customer $customer) => ['customerId' => $customer->id, 'name' => $customer->name, 'email' => $customer->email])
            ->all();
    }
}
