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
    public function test_resolves_no_console_prefix_when_no_framework_integration_package_is_loaded(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::DBAL_PACKAGE]);

        $this->assertNull(ConsoleInvocationResolver::resolveConsolePrefix($serviceConfiguration));
    }
}
