<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\TenantSharedConnection;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\Tempest\TempestConnectionReference;

/**
 * licence Apache-2.0
 */
final class TenantSharedConnectionConfiguration
{
    #[ServiceContext]
    public function defaultConnection(): TempestConnectionReference
    {
        return TempestConnectionReference::defaultConnection();
    }

    #[ServiceContext]
    public function multiTenantConfiguration(): MultiTenantConfiguration
    {
        return MultiTenantConfiguration::create(
            tenantHeaderName: 'tenant',
            tenantToConnectionMapping: [
                'tenant_a' => TempestConnectionReference::create('tenant_a'),
            ],
        );
    }
}
