<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class ServiceConfigurationModulePackagesTest extends TestCase
{
    public function test_only_test_package_is_skipped_when_module_packages_were_not_defined(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults();

        $this->assertFalse($serviceConfiguration->areSkippedPackagesDefined());
        $this->assertSame([ModulePackageList::TEST_PACKAGE], $serviceConfiguration->getSkippedModulesPackages());
    }

    public function test_loading_empty_list_of_packages_keeps_core_package(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([]);

        $this->assertTrue($serviceConfiguration->isModulePackageEnabled(ModulePackageList::CORE_PACKAGE));
        $this->assertFalse($serviceConfiguration->isModulePackageEnabled(ModulePackageList::DBAL_PACKAGE));
        $this->assertFalse($serviceConfiguration->isModulePackageEnabled(ModulePackageList::TEST_PACKAGE));
    }

    public function test_loading_given_packages_together_with_core_package(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::AMQP_PACKAGE]);

        $this->assertTrue($serviceConfiguration->isModulePackageEnabled(ModulePackageList::CORE_PACKAGE));
        $this->assertTrue($serviceConfiguration->isModulePackageEnabled(ModulePackageList::DBAL_PACKAGE));
        $this->assertTrue($serviceConfiguration->isModulePackageEnabled(ModulePackageList::AMQP_PACKAGE));
        $this->assertFalse($serviceConfiguration->isModulePackageEnabled(ModulePackageList::KAFKA_PACKAGE));
    }

    public function test_test_package_can_be_loaded_explicitly(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::TEST_PACKAGE]);

        $this->assertTrue($serviceConfiguration->isModulePackageEnabled(ModulePackageList::TEST_PACKAGE));
        $this->assertTrue($serviceConfiguration->isModulePackageEnabled(ModulePackageList::CORE_PACKAGE));
    }

    public function test_last_call_to_module_packages_wins(): void
    {
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
            ->withModulePackages([ModulePackageList::AMQP_PACKAGE]);

        $this->assertFalse($serviceConfiguration->isModulePackageEnabled(ModulePackageList::DBAL_PACKAGE));
        $this->assertTrue($serviceConfiguration->isModulePackageEnabled(ModulePackageList::AMQP_PACKAGE));
    }
}
