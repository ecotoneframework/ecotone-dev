<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Tempest\TempestConnectionReference;
use Ecotone\Dbal\MultiTenant\HeaderBasedMultiTenantConnectionFactory;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Interop\Queue\ConnectionFactory;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class TempestModule extends NoExternalConfigurationModule implements AnnotationModule
{
    /**
     * @param class-string[] $aggregateClasses
     */
    private function __construct(private array $aggregateClasses)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self($annotationRegistrationService->findAnnotatedClasses(Aggregate::class));
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        TempestModelVersionGuard::assertNoVersionedTempestModel($this->aggregateClasses, $interfaceToCallRegistry);

        $tempestConnections = ExtensionObjectResolver::resolve(TempestConnectionReference::class, $extensionObjects);

        foreach ($tempestConnections as $connection) {
            TempestConnectionDbalGuard::assertDbalInstalledFor(
                $connection->configTag === null
                    ? "TempestConnectionReference '{$connection->getReferenceName()}'"
                    : "TempestConnectionReference::create('{$connection->configTag}')"
            );
            $messagingConfiguration->registerServiceDefinition(
                $connection->getReferenceName(),
                $this->buildConnectionFactoryDefinition($connection)
            );
        }

        if (! class_exists(HeaderBasedMultiTenantConnectionFactory::class)) {
            return;
        }

        $multiTenantConfigurations = ExtensionObjectResolver::resolve(MultiTenantConfiguration::class, $extensionObjects);

        $tempestRelatedMultiTenantConfigurations = [];

        foreach ($multiTenantConfigurations as $multiTenantConfiguration) {
            foreach ($multiTenantConfiguration->tenantToConnectionMapping as $connectionReference) {
                if (! ($connectionReference instanceof TempestConnectionReference)) {
                    continue;
                }

                $tempestRelatedMultiTenantConfigurations[$multiTenantConfiguration->getReferenceName()] = $multiTenantConfiguration;

                $messagingConfiguration->registerServiceDefinition(
                    $connectionReference->getReferenceName(),
                    $this->buildConnectionFactoryDefinition($connectionReference)
                );
            }
        }

        if ($tempestRelatedMultiTenantConfigurations === []) {
            return;
        }

        $messagingConfiguration->registerServiceDefinition(
            TempestTenantDatabaseSwitcher::class,
            new Definition(
                TempestTenantDatabaseSwitcher::class,
                [],
                [
                    TempestTenantDatabaseSwitcher::class,
                    'create',
                ]
            )
        );

        $messagingConfiguration->registerMessageHandler(
            ServiceActivatorBuilder::create(TempestTenantDatabaseSwitcher::class, 'switchOn')
                ->withInputChannelName(HeaderBasedMultiTenantConnectionFactory::TENANT_ACTIVATED_CHANNEL_NAME)
        );

        $messagingConfiguration->registerMessageHandler(
            ServiceActivatorBuilder::create(TempestTenantDatabaseSwitcher::class, 'switchOff')
                ->withInputChannelName(HeaderBasedMultiTenantConnectionFactory::TENANT_DEACTIVATED_CHANNEL_NAME)
        );
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::TEMPEST_PACKAGE;
    }

    private function buildConnectionFactoryDefinition(TempestConnectionReference $connection): Definition
    {
        return new Definition(
            ConnectionFactory::class,
            [
                $connection,
            ],
            [
                TempestConnectionResolver::class,
                'resolve',
            ]
        );
    }
}
