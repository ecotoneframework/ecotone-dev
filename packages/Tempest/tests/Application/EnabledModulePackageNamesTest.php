<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class EnabledModulePackageNamesTest extends EcotoneIntegrationTestCase
{
    private const NAMESPACES = ['Test\\Ecotone\\Tempest\\Fixture\\EnabledModulePackageNames\\'];
    private const RECOGNISED_KEYS = 'cacheConfiguration, defaultErrorChannel, defaultSerializationMediaType, enabledModulePackageNames, licenceKey, loadAppNamespaces, namespaces, serviceName, test';

    private ?EcotoneConfig $bootedConfig = null;

    protected function ecotoneConfig(): EcotoneConfig
    {
        return $this->bootedConfig ?? parent::ecotoneConfig();
    }

    public function test_only_the_listed_module_packages_are_loaded(): void
    {
        $this->bootWith(new EcotoneConfig(
            namespaces: self::NAMESPACES,
            enabledModulePackageNames: [ModulePackageList::TEMPEST_PACKAGE],
        ));

        $this->assertInstanceOf(CommandBus::class, $this->container->get(CommandBus::class));
        $this->assertFalse($this->container->has(DeadLetterGateway::class));
    }

    public function test_a_listed_module_package_is_loaded(): void
    {
        $this->bootWith(new EcotoneConfig(
            namespaces: self::NAMESPACES,
            enabledModulePackageNames: [ModulePackageList::TEMPEST_PACKAGE, ModulePackageList::DBAL_PACKAGE],
        ));

        $this->assertInstanceOf(DeadLetterGateway::class, $this->container->get(DeadLetterGateway::class));
    }

    public function test_every_installed_module_package_is_loaded_when_the_key_is_left_out(): void
    {
        $this->bootWith(new EcotoneConfig(
            namespaces: self::NAMESPACES,
            licenceKey: LicenceTesting::VALID_LICENCE,
        ));

        $this->assertInstanceOf(DeadLetterGateway::class, $this->container->get(DeadLetterGateway::class));
    }

    public function test_an_empty_list_loads_core_alone(): void
    {
        $this->bootWith(new EcotoneConfig(
            namespaces: self::NAMESPACES,
            enabledModulePackageNames: [],
        ));

        $this->assertInstanceOf(CommandBus::class, $this->container->get(CommandBus::class));
        $this->assertFalse($this->container->has(DeadLetterGateway::class));
    }

    public function test_the_1x_skipped_module_package_names_key_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Ecotone does not recognise the `skippedModulePackageNames` key in EcotoneConfig. '
            . 'Since Ecotone 2.0 it is `enabledModulePackageNames`, and its meaning is inverted: `skippedModulePackageNames` listed the module packages to skip, `enabledModulePackageNames` lists the module packages to load, so moving the same list over unchanged would load exactly the packages you meant to skip. '
            . 'Either list the module packages to load under `enabledModulePackageNames` as ModulePackageList constants (Core, which includes Asynchronous, always loads), or remove the key to load every installed module package. '
            . 'The keys Ecotone reads in EcotoneConfig are: ' . self::RECOGNISED_KEYS . '.'
        );

        new EcotoneConfig(skippedModulePackageNames: [ModulePackageList::AMQP_PACKAGE]);
    }

    public function test_the_module_packages_key_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Ecotone does not recognise the `modulePackages` key in EcotoneConfig. '
            . 'It is now `enabledModulePackageNames`. `modulePackages` already listed the module packages to load, so rename the key and keep its list - unless that list was carried over from 1.x `skippedModulePackageNames`, which listed the module packages to skip and has to be inverted into the module packages to load. '
            . 'Or remove the key to load every installed module package. '
            . 'The keys Ecotone reads in EcotoneConfig are: ' . self::RECOGNISED_KEYS . '.'
        );

        new EcotoneConfig(modulePackages: [ModulePackageList::TEMPEST_PACKAGE]);
    }

    public function test_a_misspelled_key_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Ecotone does not recognise the `enabledModulePackages` key in EcotoneConfig, so it would have no effect. '
            . 'Remove it, or correct it to one of the keys Ecotone reads in EcotoneConfig: ' . self::RECOGNISED_KEYS . '.'
        );

        new EcotoneConfig(enabledModulePackages: [ModulePackageList::TEMPEST_PACKAGE]);
    }

    private function bootWith(EcotoneConfig $config): void
    {
        $this->bootedConfig = $config;
        restore_exception_handler();
        restore_error_handler();
        $this->setupKernel();
    }
}
