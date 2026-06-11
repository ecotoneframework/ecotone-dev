<?php

namespace Ecotone\SymfonyBundle\DependencyInjection;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\MessagingSystemContainer;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\CacheClearer;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\CacheWarmer;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\SymfonyConfigurationVariableService;
use Ecotone\SymfonyContainer\EcotoneContainer;
use Ecotone\SymfonyContainer\EcotoneSymfonyContainerFactory;
use Ecotone\SymfonyContainer\ExternalReferenceResolver;
use Ecotone\SymfonyContainer\RuntimeInstanceProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * licence Apache-2.0
 */
class EcotoneExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $config = $container->resolveEnvPlaceholders($config, true);

        $skippedModules = $config['skippedModulePackageNames'] ?? [];

        /** @TODO Ecotone 2.0 use ServiceContext to configure Symfony */
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withEnvironment($container->getParameter('kernel.environment'))
            ->withFailFast(in_array($container->getParameter('kernel.environment'), ['prod', 'production']) ? false : $config['failFast'])
            ->withLoadCatalog($config['loadSrcNamespaces'] ? 'src' : '')
            ->withNamespaces($config['namespaces'])
            ->withSkippedModulePackageNames($skippedModules)
        ;

        if ($config['licenceKey'] !== null) {
            $serviceConfiguration = $serviceConfiguration->withLicenceKey($config['licenceKey']);
        }

        if (isset($config['serviceName'])) {
            $serviceConfiguration = $serviceConfiguration
                ->withServiceName($config['serviceName']);
        }

        if (isset($config['defaultSerializationMediaType'])) {
            $serviceConfiguration = $serviceConfiguration
                ->withDefaultSerializationMediaType($config['defaultSerializationMediaType']);
        }
        if (isset($config['defaultMemoryLimit'])) {
            $serviceConfiguration = $serviceConfiguration
                ->withConsumerMemoryLimit($config['defaultMemoryLimit']);
        }
        if (isset($config['defaultConnectionExceptionRetry'])) {
            $retryTemplate            = $config['defaultConnectionExceptionRetry'];
            $serviceConfiguration = $serviceConfiguration
                ->withConnectionRetryTemplate(
                    RetryTemplateBuilder::exponentialBackoffWithMaxDelay(
                        $retryTemplate['initialDelay'],
                        $retryTemplate['maxAttempts'],
                        $retryTemplate['multiplier']
                    )
                );
        }
        if (isset($config['defaultErrorChannel'])) {
            $serviceConfiguration = $serviceConfiguration
                ->withDefaultErrorChannel($config['defaultErrorChannel']);
        }

        $configurationVariableService = new SymfonyConfigurationVariableService($container);

        if (! $container->has(\Psr\Container\ContainerInterface::class)) {
            $container->setAlias(\Psr\Container\ContainerInterface::class, 'service_container');
        }
        $container->register(\Ecotone\Messaging\ConfigurationVariableService::class, SymfonyConfigurationVariableService::class)->setAutowired(true)->setPublic(true);

        $container->register(ServiceCacheConfiguration::REFERENCE_NAME, ServiceCacheConfiguration::class)
            ->setArguments([
                '%kernel.build_dir%/ecotone',
                true,
            ]);

        $container->register(CacheWarmer::class)->setAutowired(true)->addTag('kernel.cache_warmer');
        $container->register(CacheClearer::class)->setAutowired(true)->addTag('kernel.cache_clearer')->setPublic(true);

        $messagingConfiguration = MessagingSystemConfiguration::prepare(
            realpath(($container->hasParameter('kernel.project_dir') ? $container->getParameter('kernel.project_dir') : $container->getParameter('kernel.root_dir') . '/..')),
            $configurationVariableService,
            $serviceConfiguration,
            enableTestPackage: $config['test']
        );

        $cacheDirectory = $container->getParameter('kernel.build_dir') . DIRECTORY_SEPARATOR . 'ecotone';
        $serviceCacheConfiguration = new ServiceCacheConfiguration($cacheDirectory, true);

        $containerBuilder = new \Ecotone\Messaging\Config\Container\ContainerBuilder();
        $containerBuilder->register(ServiceCacheConfiguration::REFERENCE_NAME, $serviceCacheConfiguration);
        $containerBuilder->addCompilerPass($messagingConfiguration);
        $containerBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
        $ecotoneContainer = EcotoneSymfonyContainerFactory::build($containerBuilder, $serviceCacheConfiguration);

        $container->register('ecotone.container', EcotoneContainer::class)
            ->setFactory([EcotoneContainerLoader::class, 'load'])
            ->setArguments([$cacheDirectory, new Reference('service_container')])
            ->setPublic(true);

        $container->register(ConfiguredMessagingSystem::class, MessagingSystemContainer::class)
            ->setFactory([new Reference('ecotone.container'), 'get'])
            ->setArguments([ConfiguredMessagingSystem::class])
            ->setPublic(true);

        foreach ($messagingConfiguration->getRegisteredGateways() as $gatewayProxyBuilder) {
            $referenceName = $gatewayProxyBuilder->getReferenceName();
            $container->register($referenceName, $gatewayProxyBuilder->getInterfaceName())
                ->setFactory([new Reference('ecotone.container'), 'get'])
                ->setArguments([$referenceName])
                ->setPublic(true);
        }

        $container->register(ProxyFactory::class, ProxyFactory::class)
            ->setFactory([new Reference('ecotone.container'), 'get'])
            ->setArguments([ProxyFactory::class])
            ->setPublic(true);

        foreach ($ecotoneContainer->getServiceIds() as $serviceId) {
            if ($container->has($serviceId) || ! (class_exists($serviceId) || interface_exists($serviceId))) {
                continue;
            }
            $container->register($serviceId, $serviceId)
                ->setFactory([new Reference('ecotone.container'), 'get'])
                ->setArguments([$serviceId])
                ->setPublic(true);
        }

        $container->register(ExternalReferenceResolver::TESTING_ALIAS_PREFIX . 'logger', LoggerInterface::class)
            ->setFactory([RuntimeInstanceProvider::class, 'provide'])
            ->setArguments([new Reference('logger')])
            ->addTag('monolog.logger', ['channel' => 'ecotone'])
            ->setPublic(true);

        $container->setParameter('ecotone.external_references', $ecotoneContainer->getExternalReferenceIds());

        foreach ($ecotoneContainer->getRegisteredConsoleCommands() as $oneTimeCommandConfiguration) {
            $definition = new Definition();
            $definition->setClass(MessagingEntrypointCommand::class);
            $definition->addArgument($oneTimeCommandConfiguration->getName());
            $definition->addArgument(serialize($oneTimeCommandConfiguration->getParameters()));
            $definition->addArgument(new Reference(ConsoleCommandRunner::class));
            $definition->addTag('console.command', ['command' => $oneTimeCommandConfiguration->getName()]);

            $container->setDefinition($oneTimeCommandConfiguration->getChannelName(), $definition);
        }

        if (! $container->hasDefinition(ConsoleCommandRunner::class)) {
            $container->register(ConsoleCommandRunner::class, ConsoleCommandRunner::class)
                ->setFactory([new Reference('ecotone.container'), 'get'])
                ->setArguments([ConsoleCommandRunner::class])
                ->setPublic(true);
        }

        $unresolvedRequiredReferences = [];
        foreach ($messagingConfiguration->getRequiredReferencesForValidation() as $referenceId => $errorMessage) {
            if (! $ecotoneContainer->has($referenceId)) {
                $unresolvedRequiredReferences[$referenceId] = $errorMessage;
                continue;
            }
            if (! $container->has($referenceId)) {
                $container->register($referenceId, class_exists($referenceId) || interface_exists($referenceId) ? $referenceId : null)
                    ->setFactory([new Reference('ecotone.container'), 'get'])
                    ->setArguments([$referenceId])
                    ->setPublic(true);
            }
        }
        $container->setParameter('ecotone.messaging_system_configuration.required_references', $unresolvedRequiredReferences);
    }
}
