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
use function Tempest\get;

/**
 * licence Apache-2.0
 */
final class EcotoneInitializer implements Initializer
{
    #[Singleton]
    public function initialize(Container $container): ConfiguredMessagingSystem
    {
        $appConfig = get(AppConfig::class);
        $environment = $appConfig->environment->value ?? 'local';
        $rootCatalog = getcwd();
        
        // Create configuration variable service
        $configurationVariableService = new TempestConfigurationVariableService();
        
        // Configure Ecotone service configuration
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
            ->withEnvironment($environment)
            ->withLoadCatalog('src') // Load from src directory by default
            ->withFailFast(false)
            ->withNamespaces(['Test\\Ecotone\\Tempest\\Fixture']) // Include test fixtures for testing
            ->withSkippedModulePackageNames([]);

        // Create annotation finder for discovery
        $annotationFinder = AnnotationFinderFactory::createForAttributes(
            realpath($rootCatalog),
            $serviceConfiguration->getNamespaces(),
            $serviceConfiguration->getEnvironment(),
            $serviceConfiguration->getLoadedCatalog() ?? '',
            MessagingSystemConfiguration::getModuleClassesFor($serviceConfiguration),
            isRunningForTesting: $environment === 'test'
        );

        // Prepare messaging system configuration
        $messagingConfiguration = MessagingSystemConfiguration::prepareWithAnnotationFinder(
            $annotationFinder,
            $configurationVariableService,
            $serviceConfiguration,
            enableTestPackage: $environment === 'test'
        );

        // Create container adapter
        $containerAdapter = new TempestContainerAdapter($container);

        // Build and configure the messaging system
        return $messagingConfiguration->buildMessagingSystemFromConfiguration($containerAdapter);
    }
}
