<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\TenantAggregate;

use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Tempest\Config\TempestConnectionReference;

/**
 * licence Apache-2.0
 */
final class TenantAggregateConfiguration
{
    #[ServiceContext]
    public function multiTenantConfiguration(): MultiTenantConfiguration
    {
        return MultiTenantConfiguration::create(
            tenantHeaderName: 'tenant',
            tenantToConnectionMapping: [
                'tenant_a' => TempestConnectionReference::create('tenant_a'),
                'tenant_b' => TempestConnectionReference::create('tenant_b'),
            ],
        );
    }
}
