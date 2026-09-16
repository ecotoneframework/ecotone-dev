<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\Attribute\MultiTenantConnection;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Support\LicensingException;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Dbal\Fixture\MultiTenant\FakeConnectionFactory;

/**
 * @internal
 */
final class MultiTenantLicensingTest extends TestCase
{
    public function test_throws_when_multi_tenant_configuration_is_used_without_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('Multi-tenancy');
        $this->expectExceptionMessage('https://docs.ecotone.tech/enterprise');

        EcotoneLite::bootstrapFlowTesting(
            [],
            ['tenant_a_connection' => new FakeConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([
                    MultiTenantConfiguration::create(
                        'tenant',
                        ['tenant_a' => 'tenant_a_connection'],
                        DbalConnectionFactory::class,
                    ),
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(false)
                        ->withTransactionOnAsynchronousEndpoints(false)
                        ->withDeduplication(false),
                ]),
        );
    }

    public function test_throws_when_multi_tenant_attribute_is_used_without_enterprise_licence(): void
    {
        $service = new class () {
            #[CommandHandler('handleWithTenantConnection')]
            public function handle(#[MultiTenantConnection] mixed $connection): void
            {
            }
        };

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('MultiTenantConnection');
        $this->expectExceptionMessage('https://docs.ecotone.tech/enterprise');

        EcotoneLite::bootstrapFlowTesting(
            [$service::class],
            [$service],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE]),
        );
    }
}
