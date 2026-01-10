<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Config;

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
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Projecting\PartitionProviderReference;
use Ecotone\Projecting\PartitionProviderRegistry;
use Ecotone\Projecting\SinglePartitionProvider;

#[ModuleAnnotation]
class PartitionProviderRegistryModule extends NoExternalConfigurationModule implements AnnotationModule
{
    /**
     * @param string[] $allProjectionNames
     */
    public function __construct(
        private array $allProjectionNames = [],
    ) {
    }

    public static function create(AnnotationFinder $annotationFinder, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $allProjectionNames = [];
        foreach ($annotationFinder->findAnnotatedClasses(ProjectionV2::class) as $projectionClassName) {
            $projectionAttribute = $annotationFinder->getAttributeForClass($projectionClassName, ProjectionV2::class);
            $allProjectionNames[] = $projectionAttribute->name;
        }

        return new self($allProjectionNames);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $partitionProviderReferences = ExtensionObjectResolver::resolve(
            PartitionProviderReference::class,
            $extensionObjects
        );

        $partitionedProjectionNames = [];
        foreach ($partitionProviderReferences as $ref) {
            $partitionedProjectionNames = array_merge($partitionedProjectionNames, $ref->getPartitionedProjectionNames());
        }
        $partitionedProjectionNames = array_unique($partitionedProjectionNames);

        $nonPartitionedProjectionNames = array_values(array_diff($this->allProjectionNames, $partitionedProjectionNames));

        $partitionProviders = array_map(
            fn (PartitionProviderReference $ref) => new Reference($ref->getReferenceName()),
            $partitionProviderReferences
        );

        $messagingConfiguration->registerServiceDefinition(
            SinglePartitionProvider::class,
            new Definition(SinglePartitionProvider::class, [$nonPartitionedProjectionNames])
        );

        $partitionProviders[] = new Reference(SinglePartitionProvider::class);

        $messagingConfiguration->registerServiceDefinition(
            PartitionProviderRegistry::class,
            new Definition(PartitionProviderRegistry::class, [$partitionProviders])
        );
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof PartitionProviderReference;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}

