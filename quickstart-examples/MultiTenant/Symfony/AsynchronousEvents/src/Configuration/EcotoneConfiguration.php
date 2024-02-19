<?php

declare(strict_types=1);

namespace App\MultiTenant\Configuration;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Laravel\Config\LaravelConnectionReference;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\SymfonyBundle\Config\SymfonyConnectionReference;

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

    #[ServiceContext]
    public function databaseChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('notifications')
                // how much time we are willing to wait for a message to be received. The lower value the quicker tenants will be switched for polling
                ->withReceiveTimeout(100);
    }
}