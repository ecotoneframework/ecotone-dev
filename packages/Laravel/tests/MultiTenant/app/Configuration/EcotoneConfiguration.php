<?php

declare(strict_types=1);

namespace App\MultiTenant\Configuration;

use Ecotone\Dbal\Api\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Laravel\Api\ExtensionObject\LaravelConnectionReference;
use Ecotone\Laravel\Api\ExtensionObject\LaravelQueueMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;

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
