<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Tempest\MultiTenant;

/**
 * licence Apache-2.0
 */
final class RegisterCustomer
{
    public function __construct(
        public readonly int $customerId,
        public readonly string $name,
    ) {
    }
}
