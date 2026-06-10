<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Dbal;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DbalConnectionRequirementWithoutConnectionTest extends TestCase
{
    public function test_throws_configuration_exception_when_dbal_connection_factory_is_not_configured(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/Dbal module requires 'Enqueue\\\\Dbal\\\\DbalConnectionFactory' to be configured/");

        EcotoneLite::bootstrap(
            classesToResolve: [],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces(['Test\\Ecotone\\Tempest\\Fixture\\Counter\\']),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
