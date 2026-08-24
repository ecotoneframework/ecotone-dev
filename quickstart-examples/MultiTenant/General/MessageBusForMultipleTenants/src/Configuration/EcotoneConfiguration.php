<?php

declare(strict_types=1);

namespace App\MultiTenant\Configuration;

use Ecotone\Api\ServiceContext;
use Ecotone\Api\Dbal\MultiTenantConfiguration;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function multiTenantConfiguration(): MultiTenantConfiguration
    {
        return MultiTenantConfiguration::create(
            tenantHeaderName: 'tenant',
            tenantToConnectionMapping: ['tenant_a' => 'tenant_a_factory', 'tenant_b' => 'tenant_b_factory'],
        );
    }
}