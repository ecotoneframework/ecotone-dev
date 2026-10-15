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
    public function test_an_absent_key_leaves_an_empty_list_in_the_tree_because_a_null_default_breaks_symfony_6_4(): void
    {
        $this->assertSame([], $this->process([])['enabledModulePackageNames']);
    }

    public function test_the_tree_builds_on_every_supported_symfony_config_version(): void
    {
        $this->assertContains('enabledModulePackageNames', array_keys((new Configuration())->getConfigTreeBuilder()->buildTree()->getChildren()));
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
