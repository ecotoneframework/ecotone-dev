<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Dbal\Api\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Tempest\Api\ExtensionObject\TempestConnectionReference;

final readonly class EcotoneConfiguration
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
