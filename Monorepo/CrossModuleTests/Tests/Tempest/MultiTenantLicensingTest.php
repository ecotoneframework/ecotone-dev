<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\Tempest;

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;

/**
 * @internal
 */
final class MultiTenantLicensingTest extends EcotoneIntegrationTestCase
{
    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();
    }

    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Monorepo\\CrossModuleTests\\Fixture\\Tempest\\MultiTenant\\'],
            enabledModulePackageNames: [ModulePackageList::TEMPEST_PACKAGE,
                ModulePackageList::DBAL_PACKAGE, ],
            test: false,
        );
    }

    protected function discoverTestLocations(): array
    {
        return [
            ...parent::discoverTestLocations(),
            new \Tempest\Discovery\DiscoveryLocation(
                'Monorepo\\CrossModuleTests\\Fixture\\Tempest\\MultiTenant\\',
                dirname(__DIR__, 2) . '/Fixture/Tempest/MultiTenant',
            ),
        ];
    }

    public function test_throws_when_multi_tenant_configuration_is_used_without_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('Multi-tenancy');
        $this->expectExceptionMessage('https://docs.ecotone.tech/enterprise');

        $this->setupKernel();
    }
}
