<?php

declare(strict_types=1);

namespace Test\EnabledModulePackageNames;

use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * licence Apache-2.0
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class EnabledModulePackageNamesTest extends TestCase
{
    private const RECOGNISED_KEYS = 'enabledModulePackageNames, licenceKey, loadSrcNamespaces, namespaces, test';

    protected function setUp(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/ecotone_symfony_enabled_module_package_names');
    }

    public function test_only_the_listed_module_packages_are_loaded(): void
    {
        $container = self::bootWith(['enabledModulePackageNames' => [ModulePackageList::SYMFONY_PACKAGE]]);

        self::assertInstanceOf(CommandBus::class, $container->get(CommandBus::class));
        self::assertFalse($container->has(DeadLetterGateway::class));
    }

    public function test_a_listed_module_package_is_loaded(): void
    {
        $container = self::bootWith(['enabledModulePackageNames' => [ModulePackageList::SYMFONY_PACKAGE, ModulePackageList::DBAL_PACKAGE]]);

        self::assertInstanceOf(DeadLetterGateway::class, $container->get(DeadLetterGateway::class));
    }

    public function test_every_installed_module_package_is_loaded_when_the_key_is_left_out(): void
    {
        $container = self::bootWith(['licenceKey' => LicenceTesting::VALID_LICENCE]);

        self::assertInstanceOf(DeadLetterGateway::class, $container->get(DeadLetterGateway::class));
    }

    public function test_an_empty_list_loads_core_alone(): void
    {
        $container = self::bootWith(['enabledModulePackageNames' => []]);

        self::assertInstanceOf(CommandBus::class, $container->get(CommandBus::class));
        self::assertFalse($container->has(DeadLetterGateway::class));
    }

    public function test_the_1x_skipped_module_package_names_key_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Ecotone does not recognise the `skippedModulePackageNames` key in the `ecotone` bundle configuration. '
            . 'Since Ecotone 2.0 it is `enabledModulePackageNames`, and its meaning is inverted: `skippedModulePackageNames` listed the module packages to skip, `enabledModulePackageNames` lists the module packages to load, so moving the same list over unchanged would load exactly the packages you meant to skip. '
            . 'Either list the module packages to load under `enabledModulePackageNames` as ModulePackageList constants (Core, which includes Asynchronous, always loads), or remove the key to load every installed module package. '
            . 'The keys Ecotone reads in the `ecotone` bundle configuration are: ' . self::RECOGNISED_KEYS . '.'
        );

        self::bootWith(['skippedModulePackageNames' => [ModulePackageList::AMQP_PACKAGE]]);
    }

    public function test_the_module_packages_key_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Ecotone does not recognise the `modulePackages` key in the `ecotone` bundle configuration. '
            . 'It is now `enabledModulePackageNames`. `modulePackages` already listed the module packages to load, so rename the key and keep its list - unless that list was carried over from 1.x `skippedModulePackageNames`, which listed the module packages to skip and has to be inverted into the module packages to load. '
            . 'Or remove the key to load every installed module package. '
            . 'The keys Ecotone reads in the `ecotone` bundle configuration are: ' . self::RECOGNISED_KEYS . '.'
        );

        self::bootWith(['modulePackages' => [ModulePackageList::SYMFONY_PACKAGE]]);
    }

    public function test_a_misspelled_key_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Ecotone does not recognise the `enabledModulePackages` key in the `ecotone` bundle configuration, so it would have no effect. '
            . 'Remove it, or correct it to one of the keys Ecotone reads in the `ecotone` bundle configuration: ' . self::RECOGNISED_KEYS . '.'
        );

        self::bootWith(['enabledModulePackages' => [ModulePackageList::SYMFONY_PACKAGE]]);
    }

    private static function bootWith(array $ecotoneConfiguration): ContainerInterface
    {
        $kernel = new EnabledModulePackageNamesKernel($ecotoneConfiguration);
        $kernel->boot();

        return $kernel->getContainer();
    }
}
