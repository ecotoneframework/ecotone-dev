<?php

namespace Ecotone\Laravel;

use const DIRECTORY_SEPARATOR;

use Ecotone\AnnotationFinder\AnnotationFinderFactory;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\Container\Compiler\ValidityCheckPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\SymfonyContainer\EcotoneSymfonyContainerFactory;
use Illuminate\Console\Events\CommandFinished;
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

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/ecotone.php',
            'ecotone'
        );

        $environment            = App::environment();
        $rootCatalog            = App::basePath();
        $useProductionCache      = in_array($environment, ['prod', 'production']) ? true : Config::get('ecotone.cacheConfiguration');
        $cacheDirectory         = $this->getCacheDirectoryPath() . DIRECTORY_SEPARATOR . 'ecotone';
        $enableTesting = Config::get('ecotone.test');

        $errorChannel = Config::get('ecotone.defaultErrorChannel');

        $skippedModules = Config::get('ecotone.skippedModulePackageNames') ?? [];
        /** @TODO Ecotone 2.0 use ServiceContext to configure Laravel */
        $applicationConfiguration = ServiceConfiguration::createWithDefaults()
            ->withEnvironment($environment)
            ->withLoadCatalog(Config::get('ecotone.loadAppNamespaces') ? 'app' : '')
            ->withFailFast(false)
            ->withNamespaces(Config::get('ecotone.namespaces') ?? [])
            ->withSkippedModulePackageNames($skippedModules)
            ->withCacheDirectoryPath($cacheDirectory);

        if (Config::get('ecotone.licenceKey') !== null) {
            $applicationConfiguration = $applicationConfiguration->withLicenceKey(Config::get('ecotone.licenceKey'));
        }

        $serializationMediaType = Config::get('ecotone.defaultSerializationMediaType');
        if ($serializationMediaType) {
            $applicationConfiguration = $applicationConfiguration
                ->withDefaultSerializationMediaType($serializationMediaType);
        }
        $serviceName = Config::get('ecotone.serviceName');
        if ($serviceName) {
            $applicationConfiguration = $applicationConfiguration
                ->withServiceName($serviceName);
        }

        if ($errorChannel) {
            $applicationConfiguration = $applicationConfiguration
                ->withDefaultErrorChannel($errorChannel);
        }

        $retryTemplate = Config::get('ecotone.defaultConnectionExceptionRetry');
        if ($retryTemplate) {
            $applicationConfiguration = $applicationConfiguration
                ->withConnectionRetryTemplate(
                    RetryTemplateBuilder::exponentialBackoffWithMaxDelay(
                        $retryTemplate['initialDelay'],
                        $retryTemplate['maxAttempts'],
                        $retryTemplate['multiplier']
                    )
                );
        }

        $applicationConfiguration = $applicationConfiguration->withExtensionObjects([new EloquentRepositoryBuilder()]);
        $applicationConfiguration = MessagingSystemConfiguration::addCorePackage($applicationConfiguration, $enableTesting);

        [$serviceCacheConfiguration, $container] = $this->prepareFromCache($useProductionCache, $rootCatalog, $applicationConfiguration, $enableTesting, $cacheDirectory);

        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);
        $this->app->singleton(ConfiguredMessagingSystem::class, fn () => $messagingSystem);
        foreach ($messagingSystem->getGatewayList() as $gatewayReference) {
            $gatewayReferenceName = $gatewayReference->getReferenceName();
            $this->app->singleton($gatewayReferenceName, fn () => $container->get($gatewayReferenceName));
        }

        $this->app->singleton(
            ConfigurationVariableService::REFERENCE_NAME,
            function () {
                return new LaravelConfigurationVariableService();
            }
        );
        $this->app->singleton(
            EloquentRepository::class,
            function () {
                return new EloquentRepository();
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
                    } elseif ($parameter->hasDefaultValue()) {
                        $commandName .= '=' . $parameter->getDefaultValue();
                    }

                    $commandName .= '}';
                }

                Artisan::command(
                    $commandName,
                    function (ConfiguredMessagingSystem $configuredMessagingSystem) {
                        /** @var ConsoleCommandRunner $consoleCommandRunner */
                        $consoleCommandRunner = $configuredMessagingSystem->getGatewayByName(ConsoleCommandRunner::class);

                        /** @var ClosureCommand $self */
                        $self      = $this;

                        /** @var ConsoleCommandResultSet $result */
                        $result = $consoleCommandRunner->execute($self->getName(), array_merge($self->arguments(), $self->options()));

                        if ($result) {
                            $self->table($result->getColumnHeaders(), $result->getRows());
                        }

                        return 0;
                    }
                );
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

    public function prepareFromCache(mixed $useProductionCache, string $rootCatalog, ServiceConfiguration $applicationConfiguration, mixed $enableTesting, string $cacheDirectory): array
    {
        $externalContainer = new LaravelPsrContainerAdapter($this->app);
        $runtimeServices = [
            ConfigurationVariableService::REFERENCE_NAME => new LaravelConfigurationVariableService(),
        ];

        if ($useProductionCache && $cacheDirectory) {
            $serviceCacheConfiguration = new ServiceCacheConfiguration($cacheDirectory, true);
            $runtimeServices[ServiceCacheConfiguration::REFERENCE_NAME] = $serviceCacheConfiguration;

            $container = EcotoneSymfonyContainerFactory::loadCached($serviceCacheConfiguration, $externalContainer, $runtimeServices);
            if ($container) {
                return [$serviceCacheConfiguration, $container];
            }
        }

        $annotationFinder = AnnotationFinderFactory::createForAttributes(
            realpath($rootCatalog),
            $applicationConfiguration->getNamespaces(),
            $applicationConfiguration->getEnvironment(),
            $applicationConfiguration->getLoadedCatalog() ?? '',
            MessagingSystemConfiguration::getModuleClassesFor($applicationConfiguration),
            isRunningForTesting: $enableTesting,
        );

        $cacheHash = $annotationFinder->getCacheMessagingFileNameBasedOnConfig(
            realpath($rootCatalog),
            $applicationConfiguration,
            Config::all(),
            $enableTesting
        );

        $serviceCacheConfiguration = new ServiceCacheConfiguration(
            $useProductionCache ? $cacheDirectory : ($cacheDirectory . DIRECTORY_SEPARATOR . $cacheHash),
            true,
        );
        $runtimeServices[ServiceCacheConfiguration::REFERENCE_NAME] = $serviceCacheConfiguration;

        $container = EcotoneSymfonyContainerFactory::loadCached($serviceCacheConfiguration, $externalContainer, $runtimeServices);

        if (! $container) {
            $configuration = MessagingSystemConfiguration::prepareWithAnnotationFinder(
                $annotationFinder,
                new LaravelConfigurationVariableService(),
                $applicationConfiguration,
                enableTestPackage: $enableTesting
            );

            $ecotoneBuilder = new ContainerBuilder();
            $ecotoneBuilder->addCompilerPass($configuration);
            $ecotoneBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
            $ecotoneBuilder->addCompilerPass(new ValidityCheckPass());

            MessagingSystemConfiguration::prepareCacheDirectory($serviceCacheConfiguration);
            $container = EcotoneSymfonyContainerFactory::build($ecotoneBuilder, $serviceCacheConfiguration, $externalContainer, $runtimeServices);
        }

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
                if (in_array($event->command, ['optimize', 'optimize:clear', 'cache:clear']) && $event->exitCode === 0) {
                    EcotoneCacheClear::clearEcotoneCacheDirectories($this->getCacheDirectoryPath());
                }
            }
        );
    }
}
