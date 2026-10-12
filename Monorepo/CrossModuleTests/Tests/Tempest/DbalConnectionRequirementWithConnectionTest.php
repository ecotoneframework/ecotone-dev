<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\Tempest;

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Tempest\EcotoneConfig;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use Tempest\Discovery\DiscoveryLocation;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DbalConnectionRequirementWithConnectionTest extends EcotoneIntegrationTestCase
{
    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Monorepo\\CrossModuleTests\\Fixture\\Tempest\\Dbal\\'],
            enabledModulePackageNames: [ModulePackageList::TEMPEST_PACKAGE,
                ModulePackageList::DBAL_PACKAGE, ],
            test: false,
        );
    }

    protected function discoverTestLocations(): array
    {
        return [
            ...parent::discoverTestLocations(),
            new DiscoveryLocation(
                'Monorepo\\CrossModuleTests\\Fixture\\Tempest\\Dbal\\',
                dirname(__DIR__, 2) . '/Fixture/Tempest/Dbal',
            ),
        ];
    }

    #[DoesNotPerformAssertions]
    public function test_does_not_throw_when_dbal_connection_is_configured(): void
    {
        $this->setupKernel();
    }
}
