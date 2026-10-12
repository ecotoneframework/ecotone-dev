<?php

namespace Test;

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\SymfonyBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class EcotoneConfigurationEnabledModulePackageNamesTest extends TestCase
{
    public function test_absent_enabled_module_package_names_key_is_null(): void
    {
        $this->assertNull($this->process([])['enabledModulePackageNames']);
    }

    public function test_empty_enabled_module_package_names_list_stays_an_empty_list(): void
    {
        $this->assertSame([], $this->process(['enabledModulePackageNames' => []])['enabledModulePackageNames']);
    }

    public function test_explicit_enabled_module_package_names_are_kept(): void
    {
        $this->assertSame(
            [ModulePackageList::DBAL_PACKAGE, ModulePackageList::AMQP_PACKAGE],
            $this->process(['enabledModulePackageNames' => [ModulePackageList::DBAL_PACKAGE, ModulePackageList::AMQP_PACKAGE]])['enabledModulePackageNames']
        );
    }

    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
