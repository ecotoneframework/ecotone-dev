<?php

namespace Ecotone\SymfonyBundle\DepedencyInjection\Compiler;

use Closure;
use Ecotone\Lite\PsrContainerReferenceSearchService;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ContainerChannelResolver;
use Ecotone\Messaging\Config\MessagingComponentsFactory;
use Ecotone\Messaging\Config\MessagingSystem;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\PreparedConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Support\Assert;
use Ecotone\SymfonyBundle\CacheWarmer\ProxyCacheWarmer;
use Ecotone\SymfonyBundle\MessagingSystemFactory;
use Ecotone\SymfonyBundle\PreparedConfigurationFromDumpFactory;
use Ecotone\SymfonyBundle\Proxy\Autoloader;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\VarExporter\VarExporter;

class EcotoneCompilerPass implements CompilerPassInterface
{
    public const  SERVICE_NAME                           = 'ecotone.service_name';
    public const  WORKING_NAMESPACES_CONFIG          = 'ecotone.namespaces';
    public const  FAIL_FAST_CONFIG                   = 'ecotone.fail_fast';
    public const  LOAD_SRC                           = 'ecotone.load_src';
    public const  DEFAULT_SERIALIZATION_MEDIA_TYPE   = 'ecotone.serializationMediaType';
    public const  ERROR_CHANNEL                      = 'ecotone.errorChannel';
    public const  DEFAULT_MEMORY_LIMIT               = 'ecotone.defaultMemoryLimit';
    public const  DEFAULT_CONNECTION_EXCEPTION_RETRY = 'ecotone.defaultChannelPollRetry';
    public const  SKIPPED_MODULE_PACKAGES   = 'ecotone.skippedModulePackageNames';
    public const         SRC_CATALOG                        = 'src';
    public const         CACHE_DIRECTORY_SUFFIX             = DIRECTORY_SEPARATOR . 'ecotone';

    /**
     * @param Container $container
     *
     * @return bool|string
     */
    public static function getRootProjectPath(ContainerInterface $container)
    {
        return realpath(($container->hasParameter('kernel.project_dir') ? $container->getParameter('kernel.project_dir') : $container->getParameter('kernel.root_dir') . '/..'));
    }

    private static function getMessagingConfiguration(ContainerInterface $container): MessagingSystemConfiguration
    {
        $ecotoneCacheDirectory    = $container->getParameter('kernel.cache_dir') . self::CACHE_DIRECTORY_SUFFIX;
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withEnvironment($container->getParameter('kernel.environment'))
            ->withFailFast($container->getParameter('kernel.environment') === 'prod' ? false : $container->getParameter(self::FAIL_FAST_CONFIG))
            ->withLoadCatalog($container->getParameter(self::LOAD_SRC) ? 'src' : '')
            ->withNamespaces($container->getParameter(self::WORKING_NAMESPACES_CONFIG))
            ->withSkippedModulePackageNames($container->getParameter(self::SKIPPED_MODULE_PACKAGES))
            ->withCacheDirectoryPath($ecotoneCacheDirectory);

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
            false,
        );
    }

    private static function dumpConfiguration(PreparedConfiguration $preparedConfiguration, string $filename): void
    {
        $code = VarExporter::export($preparedConfiguration);
        file_put_contents($filename, '<?php
return ' . $code . ';');
    }

    public function process(ContainerBuilder $container)
    {
        $messagingConfiguration = $this->getMessagingConfiguration($container);
        $cacheDirectoryPath = $container->getParameter('kernel.cache_dir') . self::CACHE_DIRECTORY_SUFFIX;
        $preparedConfigurationFilename = $cacheDirectoryPath . DIRECTORY_SEPARATOR . 'prepared_configuration.php';
        $container->setParameter('ecotone.cache_directory', '%kernel.cache_dir%'.self::CACHE_DIRECTORY_SUFFIX);
        $container->setParameter('ecotone.proxy_directory', '%ecotone.cache_directory%/proxy');
        $container->setParameter('ecotone.auto_generate_proxy', true);

        $preparedConfiguration = $messagingConfiguration->getPreparedConfiguration();

        $this->dumpConfiguration($preparedConfiguration, $preparedConfigurationFilename);

        $definition = (new Definition())
            ->setClass(PreparedConfiguration::class)
            ->setFactory([PreparedConfigurationFromDumpFactory::class, 'get'])
            ->addArgument("%ecotone.cache_directory%" . DIRECTORY_SEPARATOR . 'prepared_configuration.php')
        ;
        $container->setDefinition(PreparedConfiguration::class, $definition);

        $definition = (new Definition())
            ->setClass(MessagingComponentsFactory::class)
            ->addArgument(new Reference(PreparedConfiguration::class))
            ->addArgument(new Reference(ReferenceSearchService::class))
        ;
        $container->setDefinition('ecotone.messaging.factory', $definition);

        $definition = new Definition();
        $definition->setClass(SymfonyConfigurationVariableService::class);
        $definition->setPublic(true);
        $definition->addArgument(new Reference('service_container'));
        $container->setDefinition(ConfigurationVariableService::REFERENCE_NAME, $definition);

        $definition = new Definition();
        $definition->setClass(PsrContainerReferenceSearchService::class);
        $definition->setPublic(true);
        $definition->addArgument(new Reference('service_container'));
        $container->setDefinition(ReferenceSearchService::class, $definition);

        $definition = (new Definition())
            ->setPublic(true)
            ->setClass(InterfaceToCallRegistry::class)
            ->setFactory([new Reference('ecotone.messaging.factory'), 'getInterfaceToCallRegistry']);
        $container->setDefinition(InterfaceToCallRegistry::REFERENCE_NAME, $definition);

        $definition = (new Definition())
            ->setPublic(true)
            ->setClass(ConversionService::class)
            ->setFactory([new Reference('ecotone.messaging.factory'), 'buildConversionService']);
        $container->setDefinition(ConversionService::REFERENCE_NAME, $definition);

        $this->buildProxyFactory($container);
        $this->buildChannels($container, $preparedConfiguration);
        $this->buildGateways($container, $preparedConfiguration);
        $this->buildEventDrivenConsumers($container, $preparedConfiguration);
        $this->buildEndpointConsumers($container, $preparedConfiguration);

//        foreach ($messagingConfiguration->getRegisteredConsoleCommands() as $oneTimeCommandConfiguration) {
//            $definition = new Definition();
//            $definition->setClass(MessagingEntrypointCommand::class);
//            $definition->addArgument($oneTimeCommandConfiguration->getName());
//            $definition->addArgument(serialize($oneTimeCommandConfiguration->getParameters()));
//            $definition->addArgument(new Reference(ConsoleCommandRunner::class));
//            $definition->addTag('console.command', ['command' => $oneTimeCommandConfiguration->getName()]);
//
//            $container->setDefinition($oneTimeCommandConfiguration->getChannelName(), $definition);
//        }

//        $definition = (new Definition())
//            ->setClass(ConfiguredMessagingSystem::class)
//            ->setPublic(true)
//            ->setFactory([new Reference('ecotone.messaging.factory'), 'buildMessagingSystem'])
//        ;


        $definition = (new Definition())
            ->setClass(MessagingSystem::class)
            ->setPublic(true)
            ->addArgument(new Reference('ecotone.event_driven_consumers'))
            ->addArgument(new ServiceLocatorArgument(new TaggedIteratorArgument('ecotone.polling_consumer', 'endpointId')))
            ->addArgument(new ServiceLocatorArgument(new TaggedIteratorArgument('ecotone.gateway_proxy', 'name')))
            ->addArgument(new ServiceLocatorArgument(new TaggedIteratorArgument('ecotone.gateway_combined', 'name')))
            ->addArgument(new Reference(ChannelResolver::class))
            ->addArgument(new Reference(ReferenceSearchService::class))
            ->addArgument([]) // todo: add polling metadata
            ->addArgument([]) // todo: add console commands
        ;
        $container->setDefinition(ConfiguredMessagingSystem::class, $definition);

        if (! $container->has('logger')) {
            $definition = (new Definition())
                ->setClass(NullLogger::class)
                ->setPublic(true);
            $container->setDefinition('logger', $definition);
        }
        foreach ($messagingConfiguration->getRequiredReferences() as $requiredReference) {
            $container->getDefinition($requiredReference)->setPublic(true);
        }

        foreach ($messagingConfiguration->getOptionalReferences() as $optionalReference) {
            if ($container->has($optionalReference)) {
                $container->getDefinition($optionalReference)->setPublic(true);
            }
        }
    }

    private function buildProxyFactory(ContainerBuilder $container): void
    {
        $definition =( new Definition())
            ->setClass(ProxyFactory::class)
            ->setFactory([null, 'createWithCache'])
            ->addArgument("%ecotone.cache_directory%")
            ->setPublic(true)
            ->addTag('container.preload', ['class' => ProxyFactory::class]);
        $container->setDefinition(ProxyFactory::class, $definition);

        $definition =( new Definition())
            ->setClass(\Ecotone\SymfonyBundle\Proxy\ProxyFactory::class)
            ->addArgument(Autoloader::PROXY_NAMESPACE);
        $container->setDefinition(\Ecotone\SymfonyBundle\Proxy\ProxyFactory::class, $definition);
    }

    private function buildChannels(ContainerBuilder $container, PreparedConfiguration $preparedConfiguration): void
    {
        foreach ($preparedConfiguration->getChannelBuilders() as $channelName => $channelBuilder) {
            $definition = (new Definition())
                ->setClass(MessageChannel::class)
                ->setPublic(true)
                ->setFactory([new Reference('ecotone.messaging.factory'), 'buildChannel'])
                ->addArgument($channelName)
            ;
            $container
                ->setDefinition("ecotone.channel.$channelName", $definition)
                ->addTag('ecotone.channel', ['name' => $channelName]);
        }

        $definition = (new Definition())
            ->setClass(ContainerChannelResolver::class)
            ->setPublic(true)
            ->addArgument(new ServiceLocatorArgument(new TaggedIteratorArgument('ecotone.channel', 'name')));
        $container->setDefinition(ChannelResolver::class, $definition);
    }

    private function buildGateways(ContainerBuilder $container, PreparedConfiguration $preparedConfiguration): void
    {
        $proxyFactory = new \Ecotone\SymfonyBundle\Proxy\ProxyFactory('Ecotone\\__Proxy__');
        foreach ($preparedConfiguration->getGatewayBuilders() as $referenceName => $preparedGatewaysForReference) {
            foreach ($preparedGatewaysForReference as $gatewayBuilder) {
                $methodName = $gatewayBuilder->getRelatedMethodName();
                $definition = (new Definition())
                    ->setClass(NonProxyGateway::class)
                    ->setPublic(true)
                    ->setFactory([new Reference('ecotone.messaging.factory'), 'buildGateway'])
                    ->addArgument($referenceName)
                    ->addArgument($methodName)
                    ->addArgument(new Reference(ChannelResolver::class))
                ;
                $container
                    ->setDefinition("ecotone.gateway.$referenceName.$methodName", $definition)
                    ->addTag("ecotone.gateway.$referenceName", ['method' => $methodName]);
            }
            $definition = (new Definition())
                ->setClass($proxyFactory->getFullClassNameFor($referenceName))
                ->addArgument(new ServiceLocatorArgument(new TaggedIteratorArgument("ecotone.gateway.$referenceName", 'method')));
            $container->setDefinition($referenceName, $definition)
                ->setPublic(true)
                ->addTag('ecotone.gateway_proxy', ['name' => $referenceName]);
        }

        $definition = (new Definition())
            ->setClass(ProxyCacheWarmer::class)
            ->addArgument(array_keys($preparedConfiguration->getGatewayBuilders()))
            ->addArgument(new Reference(\Ecotone\SymfonyBundle\Proxy\ProxyFactory::class))
            ->addArgument("%ecotone.proxy_directory%")
            ->addTag('kernel.cache_warmer');
        $container->setDefinition(ProxyCacheWarmer::class, $definition);
    }

    private function buildEventDrivenConsumers(ContainerBuilder $container, PreparedConfiguration $preparedConfiguration): void
    {
        $definition = (new Definition())
            ->setClass('array')
            ->setFactory([new Reference('ecotone.messaging.factory'), 'buildEventDrivenConsumers'])
            ->addArgument(new Reference(ChannelResolver::class))
            ->addArgument(new Reference(ReferenceSearchService::class))
        ;
        $container->setDefinition('ecotone.event_driven_consumers', $definition);
    }

    private function buildEndpointConsumers(ContainerBuilder $container, PreparedConfiguration $preparedConfiguration): void
    {
        $messageHandlerBuilders = $preparedConfiguration->getMessageHandlerBuilders();
        $messageChannelBuilders = $preparedConfiguration->getChannelBuilders();
        $messageConsumerFactories = $preparedConfiguration->getConsumerFactories();
        foreach ($messageHandlerBuilders as $messageHandlerBuilder) {
            Assert::keyExists($messageChannelBuilders, $messageHandlerBuilder->getInputMessageChannelName(), "Missing channel with name {$messageHandlerBuilder->getInputMessageChannelName()} for {$messageHandlerBuilder}");
            $messageChannel = $messageChannelBuilders[$messageHandlerBuilder->getInputMessageChannelName()];
            foreach ($messageConsumerFactories as $messageHandlerConsumerBuilder) {
                if ($messageHandlerConsumerBuilder->isSupporting($messageHandlerBuilder, $messageChannel)) {
                    if ($messageHandlerConsumerBuilder->isPollingConsumer()) {
                        $endpointId = $messageHandlerBuilder->getEndpointId();
                        $definition = (new Definition())
                            ->setClass(Closure::class)
                            ->setFactory([new Reference('ecotone.messaging.factory'), 'buildEndpointConsumer'])
                            ->addArgument($endpointId)
                            ->addArgument(new Reference(ChannelResolver::class))
                            ->addArgument(new Reference(ReferenceSearchService::class));
                        $container->setDefinition("ecotone.polling_consumer.$endpointId", $definition)
                            ->addTag('ecotone.polling_consumer', ['endpointId' => $endpointId]);
                    }
                }
            }
        }

        $channelAdapterConsumerBuilders = $preparedConfiguration->getChannelAdaptersBuilders();
        foreach ($channelAdapterConsumerBuilders as $channelAdapterBuilder) {
            $endpointId = $channelAdapterBuilder->getEndpointId();
            $definition = (new Definition())
                ->setFactory([new Reference('ecotone.messaging.factory'), 'buildEndpointConsumer'])
                ->addArgument($endpointId)
                ->addArgument(new Reference(ChannelResolver::class))
                ->addArgument(new Reference(ReferenceSearchService::class));
            $container->setDefinition("ecotone.polling_consumer.$endpointId", $definition)
                ->addTag('ecotone.polling_consumer', ['endpointId' => $endpointId]);
            ;
        }
    }
}
