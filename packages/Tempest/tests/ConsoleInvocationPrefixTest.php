<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest;

use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Messaging\Config\ConsoleInvocationResolver;
use Ecotone\Messaging\Config\ModulePackageList;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConsoleInvocationPrefixTest extends TestCase
{
    public function test_resolves_tempest_console_prefix_when_tempest_package_is_loaded(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::TEMPEST_PACKAGE]);

        $this->assertSame('./tempest', ConsoleInvocationResolver::resolveConsolePrefix($serviceConfiguration));
    }
}
