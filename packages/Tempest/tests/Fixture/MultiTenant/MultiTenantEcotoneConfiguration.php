<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\MultiTenant;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Dbal\Api\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Tempest\Api\ExtensionObject\TempestConnectionReference;

/**
 * licence Apache-2.0
 */
final class MultiTenantEcotoneConfiguration
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
