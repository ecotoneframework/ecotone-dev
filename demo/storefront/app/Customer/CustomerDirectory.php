<?php

declare(strict_types=1);

namespace App\Customer;

use Ecotone\Api\Attribute\QueryHandler;
use Illuminate\Support\Str;

final class CustomerDirectory
{
    #[QueryHandler('customers.list')]
    public function list(): array
    {
        return Customer::query()
            ->whereNotIn('id', fn ($rushBuyers) => $rushBuyers->select('customer_id')->from('rush_buyers'))
            ->orderBy('name')
            ->get()
            ->map($this->summary(...))
            ->all();
    }

    #[QueryHandler('customer.detail')]
    public function detail(string $customerId): ?array
    {
        if (! Str::isUuid($customerId)) {
            return null;
        }
        $customer = Customer::query()->find($customerId);

        return $customer === null ? null : $this->summary($customer);
    }

    private function summary(Customer $customer): array
    {
        return ['customerId' => $customer->id, 'name' => $customer->name, 'email' => $customer->email];
    }
}
