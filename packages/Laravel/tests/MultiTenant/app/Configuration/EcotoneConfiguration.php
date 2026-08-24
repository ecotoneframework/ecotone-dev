<?php

declare(strict_types=1);

namespace App\MultiTenant\Configuration;

use Ecotone\Api\Dbal\MultiTenantConfiguration;
use Ecotone\Api\Laravel\LaravelConnectionReference;
use Ecotone\Api\Laravel\LaravelQueueMessageChannelBuilder;
use Ecotone\Api\ServiceContext;

/**
 * licence Apache-2.0
 */
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function multiTenantConfiguration(): MultiTenantConfiguration
    {
        return MultiTenantConfiguration::create(
            tenantHeaderName: 'tenant',
            tenantToConnectionMapping: [
                'tenant_a' => LaravelConnectionReference::create('tenant_a_connection'),
                'tenant_b' => LaravelConnectionReference::create('tenant_b_connection'),
            ],
        );
    }

    #[ServiceContext]
    public function laravelQueueConfiguration(): LaravelQueueMessageChannelBuilder
    {
        return LaravelQueueMessageChannelBuilder::create('notifications');
    }
}
