<?php

namespace Ecotone\SymfonyBundle\DepedencyInjection\Compiler;

use Ecotone\Lite\PsrContainerReferenceSearchService;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\SymfonyBundle\DepedencyInjection\MessagingEntrypointCommand;
use Ecotone\SymfonyBundle\MessagingSystemFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class EcotoneCompilerPass implements CompilerPassInterface
{
    public const  SERVICE_NAME                           = 'ecotone.service_name';
    public const  WORKING_NAMESPACES_CONFIG          = 'ecotone.namespaces';
    public const  CACHE_CONFIGURATION                   = 'ecotone.cache_configuration';
    public const  FAIL_FAST_CONFIG                   = 'ecotone.fail_fast';
    public const  TEST                   = 'ecotone.test';
    public const  LOAD_SRC                           = 'ecotone.load_src';
    public const  DEFAULT_SERIALIZATION_MEDIA_TYPE   = 'ecotone.serializationMediaType';
    public const  ERROR_CHANNEL                      = 'ecotone.errorChannel';
    public const  DEFAULT_MEMORY_LIMIT               = 'ecotone.defaultMemoryLimit';
    public const  DEFAULT_CONNECTION_EXCEPTION_RETRY = 'ecotone.defaultChannelPollRetry';
    public const  SKIPPED_MODULE_PACKAGES   = 'ecotone.skippedModulePackageNames';

    /**
     * @param Container $container
     *
     * @return bool|string
     */
    public static function getRootProjectPath(ContainerInterface $container)
    {
        return realpath(($container->hasParameter('kernel.project_dir') ? $container->getParameter('kernel.project_dir') : $container->getParameter('kernel.root_dir') . '/..'));
    }

    public static function getMessagingConfiguration(ContainerInterface $container, bool $useCachedVersion = false): Configuration
    {
        $skippedModules = $container->getParameter(self::SKIPPED_MODULE_PACKAGES);
        if (! $container->getParameter(self::TEST)) {
            $skippedModules[] = ModulePackageList::TEST_PACKAGE;
        }

        /** @TODO Ecotone 2.0 use ServiceContext to configure Symfony */
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withEnvironment($container->getParameter('kernel.environment'))
            ->withFailFast(in_array($container->getParameter('kernel.environment'), ['prod', 'production']) ? false : $container->getParameter(self::FAIL_FAST_CONFIG))
            ->withLoadCatalog($container->getParameter(self::LOAD_SRC) ? 'src' : '')
            ->withNamespaces($container->getParameter(self::WORKING_NAMESPACES_CONFIG))
            ->withSkippedModulePackageNames($skippedModules)
            ->withCacheDirectoryPath($container->getParameter('kernel.cache_dir'));

        if ($container->getParameter(self::SERVICE_NAME)) {
            $serviceConfiguration = $serviceConfiguration
                ->withServiceName($container->getParameter(self::SERVICE_NAME));
        }

        if ($container->getParameter(self::DEFAULT_SERIALIZATION_MEDIA_TYPE)) {
            $serviceConfiguration = $serviceConfiguration
                ->withDefaultSerializationMediaType($container->getParameter(self::DEFAULT_SERIALIZATION_MEDIA_TYPE));
        }
        if ($container->getParameter(self::DEFAULT_MEMORY_LIMIT)) {
            $serviceConfiguration = $serviceConfiguration
                ->withConsumerMemoryLimit($container->getParameter(self::DEFAULT_MEMORY_LIMIT));
        }
        if ($container->getParameter(self::DEFAULT_CONNECTION_EXCEPTION_RETRY)) {
            $retryTemplate            = $container->getParameter(self::DEFAULT_CONNECTION_EXCEPTION_RETRY);
            $serviceConfiguration = $serviceConfiguration
                ->withConnectionRetryTemplate(
                    RetryTemplateBuilder::exponentialBackoffWithMaxDelay(
                        $retryTemplate['initialDelay'],
                        $retryTemplate['maxAttempts'],
                        $retryTemplate['multiplier']
                    )
                );
        }
        if ($container->getParameter(self::ERROR_CHANNEL)) {
            $serviceConfiguration = $serviceConfiguration
                ->withDefaultErrorChannel($container->getParameter(self::ERROR_CHANNEL));
        }

        $configurationVariableService = new SymfonyConfigurationVariableService($container);
        return MessagingSystemConfiguration::prepare(
            self::getRootProjectPath($container),
            $configurationVariableService,
            $serviceConfiguration,
            new ServiceCacheConfiguration($serviceConfiguration->getCacheDirectoryPath(), $useCachedVersion),
        );
    }

    public function process(ContainerBuilder $container): void
    {
        $messagingConfiguration = $this->getMessagingConfiguration($container);

        $definition = new Definition();
        $definition->setClass(SymfonyConfigurationVariableService::class);
        $definition->setPublic(true);
        $definition->addArgument(new Reference('service_container'));
        $container->setDefinition(ConfigurationVariableService::REFERENCE_NAME, $definition);

        $definition = new $definition();
        $definition->setClass(CacheCleaner::class);
        $definition->addArgument(new Reference(ServiceCacheConfiguration::REFERENCE_NAME));
        $definition->setPublic(true);
        $definition->addTag('kernel.cache_clearer');
        $container->setDefinition(CacheCleaner::class, $definition);

        $definition = new $definition();
        $definition->setClass(CacheWarmer::class);
        $definition->addArgument(new Reference('service_container'));
        $definition->addArgument(new Reference(ProxyFactory::class));
        $definition->setPublic(true);
        $definition->addTag('kernel.cache_warmer');
        $container->setDefinition(CacheWarmer::class, $definition);

        $definition = new Definition();
        $definition->setClass(PsrContainerReferenceSearchService::class);
        $definition->setPublic(true);
        $definition->addArgument(new Reference('service_container'));
        $container->setDefinition(ReferenceSearchService::class, $definition);

        $useCache = in_array($container->getParameter('kernel.environment'), ['prod', 'production']) ? true : $container->getParameter(self::CACHE_CONFIGURATION);
        $definition = new Definition();
        $definition->setClass(ServiceCacheConfiguration::class);
        $definition->addArgument('%kernel.cache_dir%');
        $definition->addArgument($useCache);
        $container->setDefinition(ServiceCacheConfiguration::REFERENCE_NAME, $definition);

        $definition = new Definition();
        $definition->setClass(ProxyFactory::class);
        $definition->setFactory([ProxyFactory::class, 'createWithCache']);
        $definition->addArgument(new Reference(ServiceCacheConfiguration::REFERENCE_NAME));
        $container->setDefinition(ProxyFactory::class, $definition);

        foreach ($messagingConfiguration->getRegisteredGateways() as $gatewayProxyBuilder) {
            $definition = new Definition();
            $definition->setFactory([ProxyFactory::class, 'createFor']);
            $definition->setClass($gatewayProxyBuilder->getInterfaceName());
            $definition->addArgument($gatewayProxyBuilder->getReferenceName());
            $definition->addArgument(new Reference('service_container'));
            $definition->addArgument($gatewayProxyBuilder->getInterfaceName());
            $definition->addArgument(new Reference(ServiceCacheConfiguration::REFERENCE_NAME));
            $definition->setPublic(true);

            $container->setDefinition($gatewayProxyBuilder->getReferenceName(), $definition);
        }

        foreach ($messagingConfiguration->getRequiredReferences() as $requiredReference) {
            /** Set alias only for non gateways */
            if (in_array($requiredReference, array_merge(array_map(fn (GatewayProxyBuilder $gatewayProxyBuilder) => $gatewayProxyBuilder->getInterfaceName(), $messagingConfiguration->getRegisteredGateways()), [ReferenceSearchService::class, ChannelResolver::class, ConversionService::class]))) {
                continue;
            }

            $alias = $container->setAlias(PsrContainerReferenceSearchService::getServiceNameWithSuffix($requiredReference), $requiredReference);

            if ($alias) {
                $alias->setPublic(true);
            }
        }

        foreach ($messagingConfiguration->getOptionalReferences() as $requiredReference) {
            if ($container->has($requiredReference)) {
                $alias = $container->setAlias(PsrContainerReferenceSearchService::getServiceNameWithSuffix($requiredReference), $requiredReference);

                if ($alias) {
                    $alias->setPublic(true);
                }
            }
        }

        foreach ($messagingConfiguration->getRegisteredConsoleCommands() as $oneTimeCommandConfiguration) {
            $definition = new Definition();
            $definition->setClass(MessagingEntrypointCommand::class);
            $definition->addArgument($oneTimeCommandConfiguration->getName());
            $definition->addArgument(serialize($oneTimeCommandConfiguration->getParameters()));
            $definition->addArgument(new Reference(ConsoleCommandRunner::class));
            $definition->addTag('console.command', ['command' => $oneTimeCommandConfiguration->getName()]);

            $container->setDefinition($oneTimeCommandConfiguration->getChannelName(), $definition);
        }

        $definition = new Definition();
        $definition->setClass(MessagingSystemFactory::class);
        $definition->addArgument(new Reference('service_container'));
        $definition->addArgument(new Reference(ServiceCacheConfiguration::REFERENCE_NAME));
        $definition->addArgument(new Reference(ReferenceSearchService::class));
        $container->setDefinition(MessagingSystemFactory::class, $definition);

        $definition = new Definition();
        $definition->setClass(ConfiguredMessagingSystem::class);
        $definition->setPublic(true);
        $definition->setFactory(new Reference(MessagingSystemFactory::class));
        $container->setDefinition(ConfiguredMessagingSystem::class, $definition);
    }
}
