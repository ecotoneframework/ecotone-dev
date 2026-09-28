<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel;

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
    public function test_resolves_laravel_console_prefix_when_laravel_package_is_loaded(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::LARAVEL_PACKAGE]);

        $this->assertSame('php artisan', ConsoleInvocationResolver::resolveConsolePrefix($serviceConfiguration));
    }
}
