<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config;

use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Messaging\Config\ConsoleInvocationResolver;
use Ecotone\Messaging\Config\ModulePackageList;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConsoleInvocationResolverTest extends TestCase
{
    public function test_resolves_symfony_console_prefix_when_only_symfony_package_is_loaded(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::SYMFONY_PACKAGE]);

        $this->assertSame('bin/console', ConsoleInvocationResolver::resolveConsolePrefix($serviceConfiguration));
    }

    public function test_resolves_laravel_console_prefix_when_only_laravel_package_is_loaded(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::LARAVEL_PACKAGE]);

        $this->assertSame('php artisan', ConsoleInvocationResolver::resolveConsolePrefix($serviceConfiguration));
    }

    public function test_resolves_tempest_console_prefix_when_only_tempest_package_is_loaded(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::TEMPEST_PACKAGE]);

        $this->assertSame('./tempest', ConsoleInvocationResolver::resolveConsolePrefix($serviceConfiguration));
    }

    public function test_resolves_no_console_prefix_when_no_framework_integration_package_is_loaded(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::DBAL_PACKAGE]);

        $this->assertNull(ConsoleInvocationResolver::resolveConsolePrefix($serviceConfiguration));
    }

    public function test_prefers_symfony_when_multiple_framework_integration_packages_are_loaded(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::SYMFONY_PACKAGE, ModulePackageList::LARAVEL_PACKAGE, ModulePackageList::TEMPEST_PACKAGE]);

        $this->assertSame('bin/console', ConsoleInvocationResolver::resolveConsolePrefix($serviceConfiguration));
    }
}
