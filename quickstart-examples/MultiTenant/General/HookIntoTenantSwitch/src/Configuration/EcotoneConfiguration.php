<?php

declare(strict_types=1);

namespace App\MultiTenant\Configuration;

use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Dbal\Api\ExtensionObject\MultiTenantConfiguration;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function getMultiTenantConfiguration(): MultiTenantConfiguration
    {
        return MultiTenantConfiguration::create(
            tenantHeaderName: 'tenant',
            tenantToConnectionMapping: [
                'tenant_a' => 'tenant_a_connection',
                'tenant_b' => 'tenant_b_connection'
            ],
        );
    }

    #[ServiceContext]
    public function getDbalConfiguration(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
                    ->withDocumentStore(enableDocumentStoreStateStoredRepository: true);
    }

}