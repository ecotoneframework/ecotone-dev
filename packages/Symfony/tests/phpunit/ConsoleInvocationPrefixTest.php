<?php

declare(strict_types=1);

namespace Test;

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
    public function test_resolves_symfony_console_prefix_when_symfony_package_is_loaded(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::SYMFONY_PACKAGE]);

        $this->assertSame('bin/console', ConsoleInvocationResolver::resolveConsolePrefix($serviceConfiguration));
    }

    public function test_prefers_symfony_when_multiple_framework_integration_packages_are_enabled(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::SYMFONY_PACKAGE, ModulePackageList::LARAVEL_PACKAGE, ModulePackageList::TEMPEST_PACKAGE]);

        $this->assertSame('bin/console', ConsoleInvocationResolver::resolveConsolePrefix($serviceConfiguration));
    }
}
