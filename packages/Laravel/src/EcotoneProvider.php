<?php

namespace Ecotone\Laravel;

use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Illuminate\Foundation\Application;
use function class_exists;

use const DIRECTORY_SEPARATOR;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use Ecotone\Messaging\Config\Container\Compiler\ContainerImplementation;
use Ecotone\Messaging\Config\Container\ContainerConfig;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use ReflectionMethod;

/**
 * licence Apache-2.0
 */
class EcotoneProvider extends ServiceProvider
{

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

        $definitionHolder = null;
        $messagingSystemCachePath = $serviceCacheConfiguration->getPath() . DIRECTORY_SEPARATOR . 'messaging_system';
        if ($serviceCacheConfiguration->shouldUseCache() && file_exists($messagingSystemCachePath)) {
            /** It may fail on deserialization, then return `false` and we can build new one */
            $definitionHolder = unserialize(file_get_contents($messagingSystemCachePath));
        }

        if (! $definitionHolder) {
            $configuration = MessagingSystemConfiguration::prepare(
                $rootCatalog,
                new LaravelConfigurationVariableService(),
                $applicationConfiguration,
            );
            $definitionHolder = ContainerConfig::buildDefinitionHolder($configuration);

            if ($serviceCacheConfiguration->shouldUseCache()) {
                MessagingSystemConfiguration::prepareCacheDirectory($serviceCacheConfiguration);
                file_put_contents($messagingSystemCachePath, serialize($definitionHolder));
            }
        }

        foreach ($definitionHolder->getDefinitions() as $id => $definition) {
            $this->app->singleton($id, function () use ($definition) {
                return $this->resolveArgument($definition);
            });
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
            foreach ($definitionHolder->getRegisteredCommands() as $oneTimeCommandConfiguration) {
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
    }

    private function getCacheDirectoryPath(): string
    {
        return App::storagePath() . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'data';
    }

    private function instantiateDefinition(Definition $definition): object
    {
        $arguments = $this->resolveArgument($definition->getArguments());
        if ($definition->hasFactory()) {
            $factory = $definition->getFactory();
            if (method_exists($factory[0], $factory[1]) && (new ReflectionMethod($factory[0], $factory[1]))->isStatic()) {
                // static call
                return $factory(...$arguments);
            } else {
                // method call from a service instance
                $service = $this->app->make($factory[0]);
                return $service->{$factory[1]}(...$arguments);
            }
        } else {
            $class = $definition->getClassName();
            return new $class(...$arguments);
        }
    }

    private function resolveArgument(mixed $argument): mixed
    {
        if (is_array($argument)) {
            return array_map(fn ($argument) => $this->resolveArgument($argument), $argument);
        } elseif ($argument instanceof Definition) {
            $object = $this->instantiateDefinition($argument);
            foreach ($argument->getMethodCalls() as $methodCall) {
                $object->{$methodCall->getMethodName()}(...$this->resolveArgument($methodCall->getArguments()));
            }
            return $object;
        } elseif ($argument instanceof Reference) {
            if ($this->app->has($argument->getId())) {
                return $this->app->get($argument->getId());
            }
            if ($argument->getInvalidBehavior() === ContainerImplementation::NULL_ON_INVALID_REFERENCE) {
                return null;
            }
            if (class_exists($argument->getId())) {
                return $this->app->make($argument->getId());
            }
            throw new InvalidArgumentException("Reference to {$argument->getId()} is not found");
        } else {
            return $argument;
        }
    }
}
