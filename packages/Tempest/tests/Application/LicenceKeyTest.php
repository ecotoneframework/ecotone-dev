<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\CommandBus;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class LicenceKeyTest extends EcotoneIntegrationTestCase
{
    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\User\\'],
            skippedModulePackageNames: ModulePackageList::allPackages(),
            test: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_booting_with_licence_key_does_not_prevent_system_from_functioning(): void
    {
        $this->assertInstanceOf(
            CommandBus::class,
            $this->container->get(CommandBus::class),
        );
    }
}
