<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Container;

use Ecotone\AnnotationFinder\AnnotationFinderFactory;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Tempest\Configuration\TempestConfigurationVariableService;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;
use Tempest\Core\AppConfig;
use Tempest\Core\Environment;
use Tempest\Core\Kernel;
use function Tempest\Support\Str\starts_with;

/**
 * licence Apache-2.0
 */
final class EcotoneInitializer implements Initializer
{
    #[Singleton]
    public function initialize(Container $container): ConfiguredMessagingSystem
    {
        $kernel = $container->get(Kernel::class);
        $appConfig = $container->get(AppConfig::class);
        $environment = $appConfig->environment->value ?? 'local';
        $rootCatalog = getcwd();

        // Create configuration variable service
        $configurationVariableService = new TempestConfigurationVariableService();

        $namespaces = [];
        foreach ($kernel->discoveryLocations as $discoveryLocation) {
            if (starts_with($discoveryLocation->namespace, 'Tempest\\')) {
                continue;
            }

            $namespaces[] = $discoveryLocation->namespace;
        }

        if ($container->has(ServiceConfiguration::class)) {
            $serviceConfiguration = $container->get(ServiceConfiguration::class);
        }else {
            $serviceConfiguration = ServiceConfiguration::createWithDefaults()
                ->withEnvironment($environment)
                ->withLoadCatalog('app');
        }

        $serviceConfiguration = $serviceConfiguration
            ->withNamespaces(array_merge($namespaces, $serviceConfiguration->getNamespaces()));

        $isRunningForTesting = in_array($environment, [Environment::CI, Environment::TESTING]);
        $annotationFinder = AnnotationFinderFactory::createForAttributes(
            realpath($rootCatalog),
            $serviceConfiguration->getNamespaces(),
            $serviceConfiguration->getEnvironment(),
            $serviceConfiguration->getLoadedCatalog() ?? '',
            MessagingSystemConfiguration::getModuleClassesFor($serviceConfiguration),
            isRunningForTesting: $isRunningForTesting
        );

        // Prepare messaging system configuration
        $messagingConfiguration = MessagingSystemConfiguration::prepareWithAnnotationFinder(
            $annotationFinder,
            $configurationVariableService,
            $serviceConfiguration,
            enableTestPackage: $isRunningForTesting
        );

        return $messagingConfiguration->buildMessagingSystemFromConfiguration(
            new TempestContainerAdapter($container)
        );
    }
}
