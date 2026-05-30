<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\MultiTenant;

use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Tempest\Config\TempestConnectionReference;
use Tempest\Database\Config\MysqlConfig;
use Tempest\Database\Config\PostgresConfig;

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
                'tenant_a' => TempestConnectionReference::create('tenant_a', new PostgresConfig(
                    host: 'database',
                    port: '5432',
                    username: 'ecotone',
                    password: 'secret',
                    database: 'ecotone',
                )),
                'tenant_b' => TempestConnectionReference::create('tenant_b', new MysqlConfig(
                    host: 'database-mysql',
                    port: '3306',
                    username: 'ecotone',
                    password: 'secret',
                    database: 'ecotone',
                )),
            ],
        );
    }
}
