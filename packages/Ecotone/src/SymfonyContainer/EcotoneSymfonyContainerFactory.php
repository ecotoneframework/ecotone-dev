<?php

declare(strict_types=1);

namespace Ecotone\SymfonyContainer;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\Container\Compiler\ValidityCheckPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Container as SymfonyBaseContainer;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;
use Symfony\Component\DependencyInjection\Definition as SymfonyDefinition;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;

/**
 * licence Apache-2.0
 */
final class EcotoneSymfonyContainerFactory
{
    /**
     * @param callable(): CompilerPass $messagingConfigurationFactory invoked only on cache miss
     * @param array<string, object> $additionalRuntimeServices
     */
    public static function bootstrap(
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ConfigurationVariableService $configurationVariableService,
        ?ContainerInterface $externalContainer,
        callable $messagingConfigurationFactory,
        ?string $configHash = null,
        array $additionalRuntimeServices = [],
    ): EcotoneContainer {
        $runtimeServices = self::defaultRuntimeServices($serviceCacheConfiguration, $configurationVariableService) + $additionalRuntimeServices;

        if ($serviceCacheConfiguration->shouldUseCache()) {
            $container = self::loadCached($serviceCacheConfiguration, $externalContainer, $runtimeServices);
            if ($container) {
                return $container;
            }
        }

        $builder = new ContainerBuilder();
        $builder->addCompilerPass($messagingConfigurationFactory());
        $builder->addCompilerPass(new RegisterInterfaceToCallReferences());
        $builder->addCompilerPass(new ValidityCheckPass());

        if ($serviceCacheConfiguration->shouldUseCache()) {
            MessagingSystemConfiguration::prepareCacheDirectory($serviceCacheConfiguration);
        }

        return self::build($builder, $serviceCacheConfiguration, $externalContainer, $runtimeServices, $configHash);
    }

    /**
     * @param array<string, object> $runtimeServices
     */
    public static function build(
        ContainerBuilder $builder,
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ?ContainerInterface $externalContainer = null,
        array $runtimeServices = [],
        ?string $configHash = null,
    ): EcotoneContainer {
        $symfonyBuilder = new SymfonyContainerBuilder();
        $implementation = new SymfonyContainerImplementation(
            $symfonyBuilder,
            array_keys($runtimeServices),
            preserveRuntimeInstances: ! $serviceCacheConfiguration->shouldUseCache(),
        );
        $builder->withGeneratedClassesDirectory($serviceCacheConfiguration->getPath() . DIRECTORY_SEPARATOR . 'handlers');
        $definitionsHolder = $builder->compile();
        $implementation->process($builder);
        $symfonyBuilder->setParameter(
            SymfonyContainerImplementation::CONSOLE_COMMANDS_PARAMETER,
            serialize($definitionsHolder->getRegisteredCommands()),
        );
        $symfonyBuilder->setParameter(SymfonyContainerImplementation::CONFIG_HASH_PARAMETER, $configHash);

        if ($serviceCacheConfiguration->shouldUseCache()) {
            $symfonyBuilder->compile();
            self::dumpToCache($symfonyBuilder, $serviceCacheConfiguration);
            return self::loadCached($serviceCacheConfiguration, $externalContainer, $runtimeServices)
                ?? throw ConfigurationException::create("Failed to load dumped Ecotone container from {$serviceCacheConfiguration->getPath()}");
        }

        return self::wrapWithExternalFallback($symfonyBuilder, $externalContainer, $runtimeServices);
    }

    public static function loadCachedWithDefaults(
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ConfigurationVariableService $configurationVariableService,
        ?ContainerInterface $externalContainer = null,
    ): ?EcotoneContainer {
        return self::loadCached(
            $serviceCacheConfiguration,
            $externalContainer,
            self::defaultRuntimeServices($serviceCacheConfiguration, $configurationVariableService),
        );
    }

    /**
     * @return array<string, object>
     */
    private static function defaultRuntimeServices(
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ConfigurationVariableService $configurationVariableService,
    ): array {
        return [
            ServiceCacheConfiguration::REFERENCE_NAME => $serviceCacheConfiguration,
            ConfigurationVariableService::REFERENCE_NAME => $configurationVariableService,
        ];
    }

    /**
     * @param array<string, object> $runtimeServices
     */
    public static function loadCached(
        ServiceCacheConfiguration $serviceCacheConfiguration,
        ?ContainerInterface $externalContainer = null,
        array $runtimeServices = [],
    ): ?EcotoneContainer {
        $containerFile = self::containerFilePath($serviceCacheConfiguration);
        if (! file_exists($containerFile)) {
            return null;
        }

        $container = require $containerFile;
        if (! $container instanceof SymfonyBaseContainer) {
            return null;
        }

        return self::wrapWithExternalFallback($container, $externalContainer, $runtimeServices);
    }

    /**
     * @return string[] dumped container files, to be included in opcache preloading
     */
    public static function dumpedContainerFiles(ServiceCacheConfiguration $serviceCacheConfiguration): array
    {
        return array_merge(
            glob($serviceCacheConfiguration->getPath() . DIRECTORY_SEPARATOR . 'handlers' . DIRECTORY_SEPARATOR . '*.php') ?: [],
            glob($serviceCacheConfiguration->getPath() . DIRECTORY_SEPARATOR . 'EcotoneCachedContainer_*.php') ?: [],
        );
    }

    private static function dumpToCache(
        SymfonyContainerBuilder $symfonyBuilder,
        ServiceCacheConfiguration $serviceCacheConfiguration,
    ): void {
        $cacheDirectory = $serviceCacheConfiguration->getPath();
        if (! is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0777, true);
        }
        $generatedClassFiles = self::extractGeneratedClassFiles($symfonyBuilder);
        $dumper = new PhpDumper($symfonyBuilder);
        $placeholderClassName = 'EcotoneCachedContainerPlaceholder';
        $containerCode = $dumper->dump(['class' => $placeholderClassName]);
        $className = 'EcotoneCachedContainer_' . md5($containerCode);
        $containerCode = str_replace($placeholderClassName, $className, $containerCode);

        foreach (glob($cacheDirectory . DIRECTORY_SEPARATOR . 'EcotoneCachedContainer_*.php') ?: [] as $staleContainerFile) {
            @unlink($staleContainerFile);
        }
        file_put_contents($cacheDirectory . DIRECTORY_SEPARATOR . $className . '.php', $containerCode);
        file_put_contents(self::containerFilePath($serviceCacheConfiguration), self::loaderStub($className, $generatedClassFiles));
    }

    /**
     * @return string[] generated class files to load eagerly, keeping the dumped container usable when the cache directory is cleared mid-process
     */
    private static function extractGeneratedClassFiles(SymfonyContainerBuilder $symfonyBuilder): array
    {
        $generatedClassFiles = [];
        foreach ($symfonyBuilder->getDefinitions() as $definition) {
            self::extractGeneratedClassFilesFromDefinition($definition, $generatedClassFiles);
        }

        return array_values($generatedClassFiles);
    }

    /**
     * @param array<string, string> $generatedClassFiles
     */
    private static function extractGeneratedClassFilesFromDefinition(SymfonyDefinition $definition, array &$generatedClassFiles): void
    {
        $file = $definition->getFile();
        if ($file !== null) {
            $generatedClassFiles[$file] = $file;
            $definition->setFile(null);
        }

        self::extractGeneratedClassFilesFromArgument($definition->getArguments(), $generatedClassFiles);
        self::extractGeneratedClassFilesFromArgument($definition->getProperties(), $generatedClassFiles);
        self::extractGeneratedClassFilesFromArgument($definition->getFactory(), $generatedClassFiles);
        foreach ($definition->getMethodCalls() as [$methodName, $methodArguments]) {
            self::extractGeneratedClassFilesFromArgument($methodArguments, $generatedClassFiles);
        }
    }

    /**
     * @param array<string, string> $generatedClassFiles
     */
    private static function extractGeneratedClassFilesFromArgument(mixed $argument, array &$generatedClassFiles): void
    {
        if ($argument instanceof SymfonyDefinition) {
            self::extractGeneratedClassFilesFromDefinition($argument, $generatedClassFiles);
        } elseif (is_array($argument)) {
            foreach ($argument as $value) {
                self::extractGeneratedClassFilesFromArgument($value, $generatedClassFiles);
            }
        }
    }

    /**
     * @param string[] $generatedClassFiles
     */
    private static function loaderStub(string $className, array $generatedClassFiles): string
    {
        $generatedClassFilesCode = var_export($generatedClassFiles, true);

        return <<<PHP
            <?php

            foreach ({$generatedClassFilesCode} as \$generatedClassFile) {
                if (! is_file(\$generatedClassFile)) {
                    return null;
                }
                require_once \$generatedClassFile;
            }

            if (! class_exists('{$className}', false)) {
                if (! is_file(__DIR__ . '/{$className}.php')) {
                    return null;
                }
                require_once __DIR__ . '/{$className}.php';
            }

            return new {$className}();
            PHP;
    }

    private static function containerFilePath(ServiceCacheConfiguration $serviceCacheConfiguration): string
    {
        return $serviceCacheConfiguration->getPath() . DIRECTORY_SEPARATOR . 'ecotone_container.php';
    }

    /**
     * @param array<string, object> $runtimeServices
     */
    private static function wrapWithExternalFallback(
        SymfonyContainerInterface $symfonyContainer,
        ?ContainerInterface $externalContainer,
        array $runtimeServices = [],
    ): EcotoneContainer {
        $externalContainer ??= InMemoryPSRContainer::createEmpty();
        $container = new EcotoneContainer($symfonyContainer, $externalContainer);
        $container->set(SymfonyContainerImplementation::EXTERNAL_CONTAINER_ID, $externalContainer);
        $container->set(ContainerInterface::class, $container);
        foreach ($runtimeServices as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
    }
}
