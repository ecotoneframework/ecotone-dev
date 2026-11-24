<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\Attribute\CurrentConnectionObjectManager;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
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
            extensionObjects: [
                MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection'], DbalConnectionFactory::class, ),
                DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false)->withTransactionOnAsynchronousEndpoints(false)->withDeduplication(false),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );
    }

    public function test_throws_when_multi_tenant_attribute_is_used_without_enterprise_licence(): void
    {
        $service = new class () {
            #[CommandHandler('handleWithTenantObjectManager')]
            public function handle(#[CurrentConnectionObjectManager] mixed $objectManager): void
            {
            }
        };

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('CurrentConnectionObjectManager');
        $this->expectExceptionMessage('https://docs.ecotone.tech/enterprise');

        EcotoneLite::bootstrapFlowTesting(
            [],
            [$service],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );
    }
}
