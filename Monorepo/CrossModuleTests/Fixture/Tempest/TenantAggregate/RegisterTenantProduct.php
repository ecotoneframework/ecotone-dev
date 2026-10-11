<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Tempest\TenantAggregate;

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
