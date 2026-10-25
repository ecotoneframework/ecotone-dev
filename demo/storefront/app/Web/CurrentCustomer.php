<?php

declare(strict_types=1);

namespace App\Web;

use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Http\Request;

final class CurrentCustomer
{
    public const COOKIE = 'storefront_customer';

    public function __construct(private readonly QueryBus $queryBus)
    {
    }

    public function of(Request $request): ?array
    {
        return $this->find((string) $request->cookie(self::COOKIE));
    }

    public function find(string $customerId): ?array
    {
        foreach ($this->queryBus->sendWithRouting('customers.list') as $customer) {
            if ($customer['customerId'] === $customerId) {
                return $customer;
            }
        }

        return null;
    }
}
