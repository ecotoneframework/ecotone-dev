<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\EnabledModulePackageNames;

use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Laravel\EcotoneProvider;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Http\Kernel;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class EnabledModulePackageNamesTest extends TestCase
{
    private const RECOGNISED_KEYS = 'cacheConfiguration, defaultConnectionExceptionRetry, defaultErrorChannel, defaultSerializationMediaType, enabledModulePackageNames, licenceKey, loadAppNamespaces, namespaces, serviceName, test';

    public function test_only_the_listed_module_packages_are_loaded(): void
    {
        $app = self::bootWith(['enabledModulePackageNames' => [ModulePackageList::LARAVEL_PACKAGE]]);

        self::assertInstanceOf(CommandBus::class, $app->get(CommandBus::class));
        self::assertFalse($app->bound(DeadLetterGateway::class));
    }

    public function test_a_listed_module_package_is_loaded(): void
    {
        $app = self::bootWith(['enabledModulePackageNames' => [ModulePackageList::LARAVEL_PACKAGE, ModulePackageList::DBAL_PACKAGE]]);

        self::assertInstanceOf(DeadLetterGateway::class, $app->get(DeadLetterGateway::class));
    }

    public function test_every_installed_module_package_is_loaded_when_the_key_is_left_out(): void
    {
        $app = self::bootWith(['licenceKey' => LicenceTesting::VALID_LICENCE]);

        self::assertInstanceOf(DeadLetterGateway::class, $app->get(DeadLetterGateway::class));
    }

    public function test_an_empty_list_loads_core_alone(): void
    {
        $app = self::bootWith(['enabledModulePackageNames' => []]);

        self::assertInstanceOf(CommandBus::class, $app->get(CommandBus::class));
        self::assertFalse($app->bound(DeadLetterGateway::class));
    }

    public function test_the_shipped_configuration_names_the_key_and_loads_every_installed_module_package(): void
    {
        $shippedConfiguration = require __DIR__ . '/../../config/ecotone.php';

        self::assertArrayHasKey('enabledModulePackageNames', $shippedConfiguration);
        self::assertInstanceOf(DeadLetterGateway::class, self::bootWith([...$shippedConfiguration, 'licenceKey' => LicenceTesting::VALID_LICENCE])->get(DeadLetterGateway::class));
    }

    public function test_the_1x_skipped_module_package_names_key_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Ecotone does not recognise the `skippedModulePackageNames` key in config/ecotone.php. '
            . 'Since Ecotone 2.0 it is `enabledModulePackageNames`, and its meaning is inverted: `skippedModulePackageNames` listed the module packages to skip, `enabledModulePackageNames` lists the module packages to load, so moving the same list over unchanged would load exactly the packages you meant to skip. '
            . 'Either list the module packages to load under `enabledModulePackageNames` as ModulePackageList constants (Core, which includes Asynchronous, always loads), or remove the key to load every installed module package. '
            . 'The keys Ecotone reads in config/ecotone.php are: ' . self::RECOGNISED_KEYS . '.'
        );

        self::bootWith(['skippedModulePackageNames' => [ModulePackageList::AMQP_PACKAGE]]);
    }

    public function test_the_module_packages_key_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Ecotone does not recognise the `modulePackages` key in config/ecotone.php. '
            . 'It is now `enabledModulePackageNames`. `modulePackages` already listed the module packages to load, so rename the key and keep its list - unless that list was carried over from 1.x `skippedModulePackageNames`, which listed the module packages to skip and has to be inverted into the module packages to load. '
            . 'Or remove the key to load every installed module package. '
            . 'The keys Ecotone reads in config/ecotone.php are: ' . self::RECOGNISED_KEYS . '.'
        );

        self::bootWith(['modulePackages' => [ModulePackageList::LARAVEL_PACKAGE]]);
    }

    public function test_a_misspelled_key_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Ecotone does not recognise the `enabledModulePackages` key in config/ecotone.php, so it would have no effect. '
            . 'Remove it, or correct it to one of the keys Ecotone reads in config/ecotone.php: ' . self::RECOGNISED_KEYS . '.'
        );

        self::bootWith(['enabledModulePackages' => [ModulePackageList::LARAVEL_PACKAGE]]);
    }

    private static function bootWith(array $ecotoneConfiguration): Application
    {
        $app = require __DIR__ . '/bootstrap/app.php';
        $app->afterBootstrapping(
            LoadConfiguration::class,
            fn (Application $app) => $app['config']->set('ecotone', $ecotoneConfiguration),
        );
        $app->make(Kernel::class)->bootstrap();
        EcotoneCacheClear::clearEcotoneCacheDirectories(EcotoneProvider::getCacheDirectoryPath());

        return $app;
    }
}
