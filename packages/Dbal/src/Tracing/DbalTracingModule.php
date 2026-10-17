<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ConnectionReference;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Dbal\Database\DbalTableManagerReference;
use Ecotone\Dbal\Database\SpansTableManager;
use Ecotone\Dbal\Database\TracesTableManager;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\BootstrapConfiguration;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ConsoleInvocationResolver;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Tracing\Config\TraceStoreReference;

#[ModuleAnnotation]
/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class DbalTracingModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $tracingConfiguration = ExtensionObjectResolver::resolveUnique(TracingConfiguration::class, $extensionObjects, TracingConfiguration::createWithDefaults());
        $connectionReference = self::connectionReferenceOf($extensionObjects);
        $consoleInvocationPrefix = ConsoleInvocationResolver::resolveConsolePrefix(ExtensionObjectResolver::resolveUnique(BootstrapConfiguration::class, $extensionObjects, BootstrapConfiguration::createWithDefaults()));
        $storesOnDatabase = self::storesOnDatabase($tracingConfiguration);

        $messagingConfiguration->registerServiceDefinition(
            TracesTableManager::class,
            new Definition(TracesTableManager::class, [TracesTableManager::DEFAULT_TABLE, $storesOnDatabase, $consoleInvocationPrefix, $connectionReference])
        );
        $messagingConfiguration->registerServiceDefinition(
            SpansTableManager::class,
            new Definition(SpansTableManager::class, [SpansTableManager::DEFAULT_TABLE, $storesOnDatabase, $consoleInvocationPrefix, $connectionReference])
        );
        if (! $storesOnDatabase) {
            return;
        }

        $messagingConfiguration->registerServiceDefinition(
            DbalTraceStore::class,
            new Definition(DbalTraceStore::class, [
                new Reference($connectionReference),
                new Reference(TracesTableManager::class),
                new Reference(SpansTableManager::class),
                new Reference(EcotoneClockInterface::class),
            ])
        );
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $connectionReference = self::connectionReferenceOf($serviceExtensions);
        $extensions = [
            new DbalTableManagerReference(TracesTableManager::class, $connectionReference),
            new DbalTableManagerReference(SpansTableManager::class, $connectionReference),
        ];
        if (self::storesOnDatabase(ExtensionObjectResolver::resolveUnique(TracingConfiguration::class, $serviceExtensions, TracingConfiguration::createWithDefaults()))) {
            $extensions[] = new TraceStoreReference(DbalTraceStore::class);
        }

        return $extensions;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }

    private static function storesOnDatabase(TracingConfiguration $tracingConfiguration): bool
    {
        return $tracingConfiguration->storedTraces && ! $tracingConfiguration->storedTracesInMemory;
    }

    /**
     * @param object[] $extensionObjects
     */
    private static function connectionReferenceOf(array $extensionObjects): string
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults());
        $connectionReference = $dbalConfiguration->defaultConnectionReferenceNames[0] ?? DbalConnectionReference::DEFAULT;
        foreach (ExtensionObjectResolver::resolve(MultiTenantConfiguration::class, $extensionObjects) as $multiTenantConfiguration) {
            if ($multiTenantConfiguration->getReferenceName() !== $connectionReference) {
                continue;
            }
            if ($multiTenantConfiguration->defaultConnectionName === null) {
                if (self::storesOnDatabase(ExtensionObjectResolver::resolveUnique(TracingConfiguration::class, $extensionObjects, TracingConfiguration::createWithDefaults()))) {
                    throw ConfigurationException::create('Stored traces are written after the unit of work ended, when no tenant is active, so they need a connection that does not depend on the tenant. Use MultiTenantConfiguration::createWithDefaultConnection() to name it, or withStoredTraces(inMemory: true) for a single process.');
                }

                return $connectionReference;
            }

            return $multiTenantConfiguration->defaultConnectionName instanceof ConnectionReference ? $multiTenantConfiguration->defaultConnectionName->getReferenceName() : $multiTenantConfiguration->defaultConnectionName;
        }

        return $connectionReference;
    }
}
