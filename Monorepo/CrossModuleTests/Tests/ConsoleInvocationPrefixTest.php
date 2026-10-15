<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Messaging\Config\BootstrapConfiguration;
use Ecotone\Messaging\Config\ConsoleInvocationResolver;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ConsoleInvocationPrefixTest extends TestCase
{
    public function test_resolves_symfony_console_prefix_when_only_symfony_package_is_loaded(): void
    {
        $this->assertSame('bin/console', $this->prefixFor(ModulePackageList::SYMFONY_PACKAGE));
    }

    public function test_resolves_laravel_console_prefix_when_only_laravel_package_is_loaded(): void
    {
        $this->assertSame('php artisan', $this->prefixFor(ModulePackageList::LARAVEL_PACKAGE));
    }

    public function test_resolves_tempest_console_prefix_when_only_tempest_package_is_loaded(): void
    {
        $this->assertSame('./tempest', $this->prefixFor(ModulePackageList::TEMPEST_PACKAGE));
    }

    public function test_prefers_symfony_when_multiple_framework_integration_packages_are_loaded(): void
    {
        $this->assertSame(
            'bin/console',
            $this->prefixFor(ModulePackageList::SYMFONY_PACKAGE, ModulePackageList::LARAVEL_PACKAGE, ModulePackageList::TEMPEST_PACKAGE)
        );
    }

    private function prefixFor(string ...$frameworkPackageNames): ?string
    {
        return ConsoleInvocationResolver::resolveConsolePrefix(
            BootstrapConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ...$frameworkPackageNames])
        );
    }
}
