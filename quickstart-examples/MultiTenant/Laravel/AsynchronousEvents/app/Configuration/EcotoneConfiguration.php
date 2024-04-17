<?php

declare(strict_types=1);

namespace App\MultiTenant\Configuration;

use Ecotone\Laravel\Config\LaravelConnectionReference;
use Ecotone\Laravel\Queue\LaravelQueueMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function multiTenantConfiguration(): MultiTenantConfiguration
    {
        return MultiTenantConfiguration::create(
            tenantHeaderName: 'tenant',
            tenantToConnectionMapping: [
                'tenant_a' => LaravelConnectionReference::create('tenant_a_connection'),
                'tenant_b' => LaravelConnectionReference::create('tenant_b_connection')
            ],
        );
    }

    #[ServiceContext]
    public function laravelQueueConfiguration()
    {
        return LaravelQueueMessageChannelBuilder::create('notifications');
    }
}