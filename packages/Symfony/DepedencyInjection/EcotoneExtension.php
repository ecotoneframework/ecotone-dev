<?php

namespace Ecotone\SymfonyBundle\DepedencyInjection;

use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\SymfonyBundle\DepedencyInjection\Compiler\SymfonyConfigurationVariableService;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

class EcotoneExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $skippedModules = $config['skippedModulePackageNames'] ?? [];
        if (! $config['test']) {
            $skippedModules[] = ModulePackageList::TEST_PACKAGE;
        }

        /** @TODO Ecotone 2.0 use ServiceContext to configure Symfony */
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withEnvironment($container->getParameter('kernel.environment'))
            ->withFailFast(in_array($container->getParameter('kernel.environment'), ['prod', 'production']) ? false : $config['failFast'])
            ->withLoadCatalog($config['loadSrcNamespaces'] ? 'src' : '')
            ->withNamespaces($config['namespaces'])
            ->withSkippedModulePackageNames($skippedModules)
//            ->withCacheDirectoryPath($container->getParameter('kernel.cache_dir'))
        ;

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

        $messagingConfiguration = MessagingSystemConfiguration::prepare(
            realpath(($container->hasParameter('kernel.project_dir') ? $container->getParameter('kernel.project_dir') : $container->getParameter('kernel.root_dir') . '/..')),
            $configurationVariableService,
            $serviceConfiguration,
            new ServiceCacheConfiguration($serviceConfiguration->getCacheDirectoryPath(), true),
        );

        $container->register(ServiceCacheConfiguration::class)
            ->setArguments([
                $serviceConfiguration->getCacheDirectoryPath(),
                true,
            ]);

        $messagingConfiguration->buildInContainer(new SymfonyContainerAdapter($container));

    }
}
