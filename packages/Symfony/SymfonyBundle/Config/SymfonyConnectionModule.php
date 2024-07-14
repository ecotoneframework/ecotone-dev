<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Interop\Queue\ConnectionFactory;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class SymfonyConnectionModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public const DOCTRINE_DBAL_CONNECTION_PREFIX = 'ecotone.doctrine.dbal.connection_';

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $laravelRelatedMultiTenantConfigurations = [];
        $symfonyConnections = ExtensionObjectResolver::resolve(SymfonyConnectionReference::class, $extensionObjects);
        $multiTenantConfigurations = ExtensionObjectResolver::resolve(MultiTenantConfiguration::class, $extensionObjects);

        foreach ($multiTenantConfigurations as $multiTenantConfiguration) {
            foreach ($multiTenantConfiguration->getTenantToConnectionMapping() as $connectionReference) {
                if ($connectionReference instanceof SymfonyConnectionReference) {
                    $symfonyConnections[] = $connectionReference;
                    $laravelRelatedMultiTenantConfigurations[$multiTenantConfiguration->getReferenceName()] = $multiTenantConfiguration;
                }
            }
        }

        $symfonyConnections = array_unique($symfonyConnections);
        foreach ($symfonyConnections as $connection) {
            if ($connection->isManagerRegistryBasedConnection()) {
                $messagingConfiguration->registerServiceDefinition(
                    $connection->getReferenceName(),
                    new Definition(
                        ConnectionFactory::class,
                        [
                            Reference::to($connection->getManagerRegistryReference()),
                            $connection->getConnectionName(),
                        ],
                        [
                            DbalConnection::class,
                            'createForManagerRegistry',
                        ]
                    )
                );
            } else {
                $referenceToConnection = self::DOCTRINE_DBAL_CONNECTION_PREFIX . $connection->getReferenceName();
                $messagingConfiguration->registerServiceDefinition(
                    $referenceToConnection,
                    new Definition(
                        ConnectionFactory::class,
                        [
                            $connection->getConnectionName(),
                        ],
                        [
                            'doctrine',
                            'getConnection',
                        ]
                    )
                );

                $messagingConfiguration->registerServiceDefinition(
                    $connection->getReferenceName(),
                    new Definition(
                        ConnectionFactory::class,
                        [
                            Reference::to($referenceToConnection),
                        ],
                        [
                            DbalConnection::class,
                            'create',
                        ]
                    )
                );
            }
        }
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof SymfonyConnectionReference || $extensionObject instanceof MultiTenantConfiguration;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::SYMFONY_PACKAGE;
    }
}
