<?php

namespace Test;

use Ecotone\Messaging\Config\ModulePackageList;
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
final class EcotoneConfigurationModulePackagesTest extends TestCase
{
    public function test_absent_module_packages_key_is_null(): void
    {
        $this->assertNull($this->process([])['modulePackages']);
    }

    public function test_empty_module_packages_list_stays_an_empty_list(): void
    {
        $this->assertSame([], $this->process(['modulePackages' => []])['modulePackages']);
    }

    public function test_explicit_module_packages_are_kept(): void
    {
        $this->assertSame(
            [ModulePackageList::DBAL_PACKAGE, ModulePackageList::AMQP_PACKAGE],
            $this->process(['modulePackages' => [ModulePackageList::DBAL_PACKAGE, ModulePackageList::AMQP_PACKAGE]])['modulePackages']
        );
    }

    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
