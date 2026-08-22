<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\MultiTenant;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;
use Test\Ecotone\Tempest\TempestTestPaths;

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
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\MultiTenant\\'],
            skippedModulePackageNames: ModulePackageList::allPackagesExcept([
                ModulePackageList::TEMPEST_PACKAGE,
                ModulePackageList::DBAL_PACKAGE,
            ]),
            test: false,
        );
    }

    protected function discoverTestLocations(): array
    {
        return [
            ...parent::discoverTestLocations(),
            new \Tempest\Discovery\DiscoveryLocation(
                'Test\\Ecotone\\Tempest\\Fixture\\MultiTenant\\',
                TempestTestPaths::fixturePath() . '/MultiTenant',
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
