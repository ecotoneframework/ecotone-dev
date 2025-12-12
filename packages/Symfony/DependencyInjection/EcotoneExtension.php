<?php

namespace Ecotone\SymfonyBundle\DependencyInjection;

use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\CacheClearer;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\CacheWarmer;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\SymfonyConfigurationVariableService;
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

        $container->register(\Ecotone\Messaging\ConfigurationVariableService::class, SymfonyConfigurationVariableService::class)->setAutowired(true);

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

        $containerBuilder = new \Ecotone\Messaging\Config\Container\ContainerBuilder();
        $containerBuilder->addCompilerPass($messagingConfiguration);
        $containerBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
        $containerBuilder->addCompilerPass(new SymfonyContainerAdapter($container));
        $definitionHolder = $containerBuilder->compile();

        $container->getDefinition(LoggingGateway::class)->addTag('monolog.logger', ['channel' => 'ecotone']);

        foreach ($definitionHolder->getRegisteredCommands() as $oneTimeCommandConfiguration) {
            $definition = new Definition();
            $definition->setClass(MessagingEntrypointCommand::class);
            $definition->addArgument($oneTimeCommandConfiguration->getName());
            $definition->addArgument(serialize($oneTimeCommandConfiguration->getParameters()));
            $definition->addArgument(new Reference(ConsoleCommandRunner::class));
            $definition->addTag('console.command', ['command' => $oneTimeCommandConfiguration->getName()]);

            $container->setDefinition($oneTimeCommandConfiguration->getChannelName(), $definition);
        }

        $container->setParameter('ecotone.messaging_system_configuration.required_references', $messagingConfiguration->getRequiredReferencesForValidation());
    }
}
