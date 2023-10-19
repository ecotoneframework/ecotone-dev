<?php

namespace Ecotone\Laravel;

use Ecotone\Lite\InMemoryContainerImplementation;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use const DIRECTORY_SEPARATOR;

use Ecotone\Lite\PsrContainerReferenceSearchService;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;

use Ecotone\Messaging\Config\ConsoleCommandResultSet;

use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;

use Ecotone\Messaging\Config\ServiceConfiguration;

use Ecotone\Messaging\ConfigurationVariableService;

use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Messaging\Handler\Logger\LoggingHandlerBuilder;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class EcotoneProvider extends ServiceProvider
{
    public const MESSAGING_SYSTEM_REFERENCE = ConfiguredMessagingSystem::class;

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
        $useCache               = in_array($environment, ['prod', 'production']) ? true : Config::get('ecotone.cacheConfiguration');
        $cacheDirectory         = $this->getCacheDirectoryPath();

        $errorChannel = Config::get('ecotone.defaultErrorChannel');

        $skippedModules = Config::get('ecotone.skippedModulePackageNames');
        if (! Config::get('ecotone.test')) {
            $skippedModules[] = ModulePackageList::TEST_PACKAGE;
        }

        /** @TODO Ecotone 2.0 use ServiceContext to configure Laravel */
        $applicationConfiguration = ServiceConfiguration::createWithDefaults()
            ->withEnvironment($environment)
            ->withLoadCatalog(Config::get('ecotone.loadAppNamespaces') ? 'app' : '')
            ->withFailFast(false)
            ->withNamespaces(Config::get('ecotone.namespaces'))
            ->withSkippedModulePackageNames($skippedModules)
            ->withCacheDirectoryPath($cacheDirectory);

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

        $serviceCacheConfiguration = new ServiceCacheConfiguration($cacheDirectory, $useCache);
        $configuration = MessagingSystemConfiguration::prepare(
            $rootCatalog,
            new LaravelConfigurationVariableService(),
            $applicationConfiguration,
        );

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

        $this->app->singleton(ProxyFactory::class, function (Application $app) {
            $cacheConfiguration = $app->get(ServiceCacheConfiguration::REFERENCE_NAME);
            return new ProxyFactory($cacheConfiguration->shouldUseCache() ? $cacheConfiguration->getPath() : null);
        });

        foreach ($configuration->getRegisteredGateways() as $registeredGateway) {
            $this->app->singleton(
                $registeredGateway->getReferenceName(),
                function (Application $app) use ($registeredGateway) {
                    return $app->make(ProxyFactory::class)
                        ->createFor(
                            $registeredGateway->getReferenceName(),
                            $app->make(ConfiguredMessagingSystem::class),
                            $registeredGateway->getInterfaceName());
                }
            );
        }

        if ($this->app->runningInConsole()) {
            foreach ($configuration->getRegisteredConsoleCommands() as $oneTimeCommandConfiguration) {
                $commandName = $oneTimeCommandConfiguration->getName();

                foreach ($oneTimeCommandConfiguration->getParameters() as $parameter) {
                    $commandName .= $parameter->isOption() ? ' {--' : ' {';
                    $commandName .= $parameter->getName();

                    if ($parameter->hasDefaultValue()) {
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

        $ecotoneContainer = InMemoryPSRContainer::createEmpty();
        $this->app->singleton(
            ConfiguredMessagingSystem::class,
            function () use ($ecotoneContainer) {
                return $ecotoneContainer->get(ConfiguredMessagingSystem::class);
            }
        );
        $ecotoneBuilder = new ContainerBuilder();
        $ecotoneBuilder->addCompilerPass($configuration);
        $ecotoneBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
        $ecotoneBuilder->addCompilerPass(new InMemoryContainerImplementation($ecotoneContainer, $this->app));
        $ecotoneBuilder->compile();


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

        if (! $this->app->has(LoggingHandlerBuilder::LOGGER_REFERENCE)) {
            $this->app->singleton(
                LoggingHandlerBuilder::LOGGER_REFERENCE,
                function (Application $app) {
                    if ($app->runningInConsole()) {
                        return new CombinedLogger($app->get('log'), new EchoLogger());
                    }

                    return $app->get('log');
                }
            );
        }
    }

    private function getCacheDirectoryPath(): string
    {
        return App::storagePath() . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ecotone';
    }
}
