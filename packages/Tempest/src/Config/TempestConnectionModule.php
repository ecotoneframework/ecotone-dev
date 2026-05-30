<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
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
use Tempest\Database\Config\DatabaseConfig;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class TempestConnectionModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $tempestConnections = ExtensionObjectResolver::resolve(TempestConnectionReference::class, $extensionObjects);

        foreach ($tempestConnections as $connection) {
            $messagingConfiguration->registerServiceDefinition(
                $connection->getReferenceName(),
                new Definition(
                    ConnectionFactory::class,
                    [
                        $connection,
                        new Reference(DatabaseConfig::class),
                    ],
                    [
                        TempestConnectionResolver::class,
                        'resolve',
                    ]
                )
            );
        }
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof TempestConnectionReference;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::TEMPEST_PACKAGE;
    }
}
