<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Interop\Queue\ConnectionFactory;

#[ModuleAnnotation]
final class LaravelConnectionModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $laravelRelatedMultiTenantConfigurations = [];
        $laravelConnections = ExtensionObjectResolver::resolve(LaravelConnectionReference::class, $extensionObjects);
        $multiTenantConfigurations = ExtensionObjectResolver::resolve(MultiTenantConfiguration::class, $extensionObjects);

        foreach ($multiTenantConfigurations as $multiTenantConfiguration) {
            foreach ($multiTenantConfiguration->getTenantToConnectionMapping() as $connectionReference) {
                if ($connectionReference instanceof LaravelConnectionReference) {
                    $laravelConnections[] = $connectionReference;
                    $laravelRelatedMultiTenantConfigurations[$multiTenantConfiguration->getReferenceName()] = $multiTenantConfiguration;
                }
            }
        }

        $laravelConnections = array_unique($laravelConnections);
        foreach ($laravelConnections as $connection) {
            $messagingConfiguration->registerServiceDefinition(
                $connection->getReferenceName(),
                new Definition(
                    ConnectionFactory::class,
                    [
                        $connection
                    ],
                    [
                        LaravelConnectionResolver::class,
                        'resolveLaravelConnection'
                    ]
                )
            );
        }

        $messagingConfiguration->registerServiceDefinition(
            LaravelTenantDatabaseSwitcher::class,
            new Definition(
                LaravelTenantDatabaseSwitcher::class,
                [],
                [
                    LaravelTenantDatabaseSwitcher::class,
                    'create'
                ]
            )
        );
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof LaravelConnectionReference || $extensionObject instanceof MultiTenantConfiguration;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::LARAVEL_PACKAGE;
    }
}