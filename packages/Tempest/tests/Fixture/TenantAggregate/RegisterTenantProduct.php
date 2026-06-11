<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\TenantAggregate;

/**
 * licence Apache-2.0
 */
final class RegisterTenantProduct
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
