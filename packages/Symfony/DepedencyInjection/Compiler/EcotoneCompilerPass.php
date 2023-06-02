<?php

namespace Ecotone\SymfonyBundle\DepedencyInjection\Compiler;

use Ecotone\Lite\PsrContainerReferenceSearchService;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ProxyGenerator;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
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

    public static function getMessagingConfiguration(ContainerInterface $container): MessagingSystemConfiguration
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

    private function dumpConfiguration(MessagingSystemConfiguration $messagingConfiguration, string $filename): void
    {
        $preparedConfiguration = $messagingConfiguration->getPreparedConfiguration();
        $code = VarExporter::export($preparedConfiguration);
        file_put_contents($filename, '<?php
return ' . $code . ';');
    }

    public function process(ContainerBuilder $container)
    {
        $messagingConfiguration = $this->getMessagingConfiguration($container);
        $cacheDirectoryPath = $container->getParameter('kernel.cache_dir') . self::CACHE_DIRECTORY_SUFFIX;
        $preparedConfigurationFilename = $cacheDirectoryPath . DIRECTORY_SEPARATOR . 'prepared_configuration.php';

        $this->dumpConfiguration($messagingConfiguration, $preparedConfigurationFilename);

        $definition = new Definition();
        $definition->setClass(SymfonyConfigurationVariableService::class);
        $definition->setPublic(true);
        $definition->addArgument(new Reference('service_container'));
        $container->setDefinition(ConfigurationVariableService::REFERENCE_NAME, $definition);

        $definition = new $definition();
        $definition->setClass(CacheCleaner::class);
        $definition->setPublic(true);
        $definition->addTag('kernel.cache_clearer');
        $container->setDefinition(CacheCleaner::class, $definition);

        $definition = new Definition();
        $definition->setClass(PsrContainerReferenceSearchService::class);
        $definition->setPublic(true);
        $definition->addArgument(new Reference('service_container'));
        $container->setDefinition(ReferenceSearchService::class, $definition);

        foreach ($messagingConfiguration->getRegisteredGateways() as $gatewayProxyBuilder) {
            $definition = new Definition();
            $definition->setFactory([ProxyGenerator::class, 'createFor']);
            $definition->setClass($gatewayProxyBuilder->getInterfaceName());
            $definition->addArgument($gatewayProxyBuilder->getReferenceName());
            $definition->addArgument(new Reference('service_container'));
            $definition->addArgument($gatewayProxyBuilder->getInterfaceName());
            $definition->addArgument('%kernel.cache_dir%'.self::CACHE_DIRECTORY_SUFFIX);
            $definition->addArgument($container->getParameter(self::FAIL_FAST_CONFIG));
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

        $definition = (new Definition())
            ->setClass(ConfiguredMessagingSystem::class)
            ->setPublic(true)
            ->setFactory([MessagingSystemFactory::class, 'create'])
            ->addArgument(new Reference(ReferenceSearchService::class))
            ->addArgument($preparedConfigurationFilename)
        ;
        $container->setDefinition(ConfiguredMessagingSystem::class, $definition);
    }
}
