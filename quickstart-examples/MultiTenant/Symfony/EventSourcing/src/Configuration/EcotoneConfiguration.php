<?php

declare(strict_types=1);

namespace App\MultiTenant\Configuration;

use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\Laravel\Api\ExtensionObject\LaravelConnectionReference;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Dbal\Api\ExtensionObject\MultiTenantConfiguration;
use Ecotone\SymfonyBundle\Api\ExtensionObject\SymfonyConnectionReference;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function multiTenantConfiguration(): MultiTenantConfiguration
    {
        return MultiTenantConfiguration::create(
            tenantHeaderName: 'tenant',
            tenantToConnectionMapping: [
                'tenant_a' => SymfonyConnectionReference::createForManagerRegistry('tenant_a_connection'),
                'tenant_b' => SymfonyConnectionReference::createForManagerRegistry('tenant_b_connection')
            ],
        );
    }
}