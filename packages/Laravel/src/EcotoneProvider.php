<?php

namespace Ecotone\Laravel;

use const DIRECTORY_SEPARATOR;

use Ecotone\Api\Console\ConsoleCommandResultSet;
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\BootstrapConfiguration;
use Ecotone\Messaging\Config\FrameworkConfigurationKeys;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\Console\DelegatingConsoleWriter;
use Ecotone\Messaging\Console\SymfonyConsoleWriter;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\SymfonyContainer\ContainerCacheLayout;
use Ecotone\SymfonyContainer\EcotoneSymfonyContainerFactory;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

/**
 * licence Apache-2.0
 */
class EcotoneProvider extends ServiceProvider
{
    public const MESSAGING_SYSTEM_FILE_NAME = 'messaging_system';
    public const PRODUCTION_CACHE_KEY = 'ecotone.cache.name';
    private const SHIPPED_CONFIGURATION_PATH = __DIR__ . '/../config/ecotone.php';

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            self::SHIPPED_CONFIGURATION_PATH,
            'ecotone'
        );
        FrameworkConfigurationKeys::refuseUnrecognisedKeys(
            givenKeys: array_keys(Config::get('ecotone', [])),
            recognisedKeys: array_keys(require self::SHIPPED_CONFIGURATION_PATH),
            configurationLocation: 'config/ecotone.php',
        );

        $environment            = App::environment();
        $rootCatalog            = App::basePath();
        $useProductionCache      = in_array($environment, ['prod', 'production']) || ($this->app instanceof Application && $this->app->configurationIsCached());
        $cacheDirectory         = $this->getCacheDirectoryPath() . DIRECTORY_SEPARATOR . 'ecotone';
        $enableTesting = Config::get('ecotone.test');

        $enabledModulePackageNames = Config::get('ecotone.' . FrameworkConfigurationKeys::ENABLED_MODULE_PACKAGE_NAMES);
        $applicationConfiguration = BootstrapConfiguration::createWithDefaults()
            ->withEnvironment($environment)
            ->withLoadCatalog(Config::get('ecotone.loadAppNamespaces') ? 'app' : '')
            ->withNamespaces(Config::get('ecotone.namespaces') ?? [])
            ->withCacheDirectoryPath($cacheDirectory);

        if ($enabledModulePackageNames !== null) {
            $applicationConfiguration = $applicationConfiguration->withEnabledModulePackageNames($enabledModulePackageNames);
        }

        if (Config::get('ecotone.licenceKey') !== null) {
            $applicationConfiguration = $applicationConfiguration->withLicenceKey(Config::get('ecotone.licenceKey'));
        }

        $applicationConfiguration = $applicationConfiguration->withExtensionObjects([new EloquentRepositoryBuilder()]);
        $applicationConfiguration = MessagingSystemConfiguration::addCorePackage($applicationConfiguration, $enableTesting);

        [$serviceCacheConfiguration, $container] = $this->prepareFromCache($useProductionCache, $rootCatalog, $applicationConfiguration, $enableTesting, $cacheDirectory);

        $container->registerBridgesInto(
            fn (string $referenceName, string $interfaceName, callable $factory) => $this->app->singleton($referenceName, fn () => $factory()),
        );

        $this->app->singleton(
            ConfigurationVariableService::REFERENCE_NAME,
            function () {
                return new LaravelConfigurationVariableService();
            }
        );
        $this->app->singleton(
            ServiceCacheConfiguration::REFERENCE_NAME,
            fn () => $serviceCacheConfiguration
        );

        if ($this->app->runningInConsole()) {
            foreach ($container->getRegisteredConsoleCommands() as $oneTimeCommandConfiguration) {
                $commandName = $oneTimeCommandConfiguration->getName();

                foreach ($oneTimeCommandConfiguration->getParameters() as $parameter) {
                    $commandName .= $parameter->isOption() ? ' {--' : ' {';
                    $commandName .= $parameter->getName();

                    if ($parameter->isArray()) {
                        $commandName .= '=*';
                    } elseif (! $parameter->isOption() && $parameter->hasDefaultValue() && $parameter->getDefaultValue() === null) {
                        $commandName .= '?';
                    } elseif ($parameter->hasDefaultValue()) {
                        $commandName .= '=' . $parameter->getDefaultValue();
                    }

                    $commandName .= '}';
                }

                $artisanCommand = Artisan::command(
                    $commandName,
                    function (ConfiguredMessagingSystem $configuredMessagingSystem) {
                        /** @var ConsoleCommandRunner $consoleCommandRunner */
                        $consoleCommandRunner = $configuredMessagingSystem->getGatewayByName(ConsoleCommandRunner::class);

                        /** @var ClosureCommand $self */
                        $self      = $this;

                        /** @var DelegatingConsoleWriter $delegatingWriter */
                        $delegatingWriter = $configuredMessagingSystem->getServiceFromContainer(ConsoleWriter::class);

                        /** @var ConsoleCommandResultSet $result */
                        $result = $delegatingWriter->executeWith(
                            new SymfonyConsoleWriter($self->getOutput()),
                            fn () => $consoleCommandRunner->execute($self->getName(), array_merge($self->arguments(), $self->options()))
                        );

                        if ($result) {
                            $self->table($result->columnHeaders, $result->rows);
                        }

                        return 0;
                    }
                );
                $artisanCommand->purpose($oneTimeCommandConfiguration->getDescription());
                foreach ($oneTimeCommandConfiguration->getParameters() as $parameter) {
                    if ($parameter->isOption() && is_bool($parameter->getDefaultValue())) {
                        $artisanCommand->getDefinition()->getOption($parameter->getName())->setDefault($parameter->getDefaultValue());
                    }
                }
            }
        }
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes(
            [
                __DIR__ . '/../config/ecotone.php' => config_path('ecotone.php'),
            ],
            'ecotone-config'
        );

        if (! $this->app->has('logger')) {
            $this->app->singleton('logger', LaravelLogger::class);
        }

        // Hook into Laravel's optimization commands to clear Ecotone cache
        $this->registerOptimizationHooks();
    }

    public static function getCacheDirectoryPath(): string
    {
        return App::storagePath() . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'data';
    }

    public function prepareFromCache(mixed $useProductionCache, string $rootCatalog, BootstrapConfiguration $applicationConfiguration, mixed $enableTesting, string $cacheDirectory): array
    {
        $externalContainer = new LaravelPsrContainerAdapter($this->app);

        if ($useProductionCache && $cacheDirectory) {
            $serviceCacheConfiguration = new ServiceCacheConfiguration($cacheDirectory, true);
            $container = EcotoneSymfonyContainerFactory::loadCachedWithDefaults($serviceCacheConfiguration, new LaravelConfigurationVariableService(), $externalContainer);
            if ($container) {
                return [$serviceCacheConfiguration, $container];
            }
        }

        $cacheLayout = ContainerCacheLayout::resolve(
            $rootCatalog,
            $applicationConfiguration,
            $cacheDirectory,
            shouldUseCache: true,
            useHashSubDirectory: ! $useProductionCache,
            configurationVariables: Config::all(),
            enableTesting: (bool) $enableTesting,
        );
        $annotationFinder = $cacheLayout->annotationFinder;
        $serviceCacheConfiguration = $cacheLayout->serviceCacheConfiguration;
        $cacheHash = $cacheLayout->configHash;

        $container = EcotoneSymfonyContainerFactory::bootstrap(
            $serviceCacheConfiguration,
            new LaravelConfigurationVariableService(),
            $externalContainer,
            fn () => MessagingSystemConfiguration::prepareWithAnnotationFinder(
                $annotationFinder,
                new LaravelConfigurationVariableService(),
                $applicationConfiguration,
                enableTestPackage: $enableTesting
            ),
            $cacheHash,
        );

        return [$serviceCacheConfiguration, $container];
    }

    /**
     * Register hooks to clear Ecotone cache when Laravel optimization commands are run
     */
    private function registerOptimizationHooks(): void
    {
        $this->app['events']->listen(
            CommandFinished::class,
            function ($event) {
                // Clear Ecotone cache when optimize commands finishes successfully
                if (in_array($event->command, ['optimize', 'optimize:clear', 'cache:clear', 'config:cache', 'config:clear']) && $event->exitCode === 0) {
                    EcotoneCacheClear::clearEcotoneCacheDirectories($this->getCacheDirectoryPath());
                }
            }
        );
    }
}
