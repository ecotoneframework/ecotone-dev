<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Consumer;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

/**
 * Module for registering DBAL consumer position tracker
 * licence Apache-2.0
 */
#[ModuleAnnotation]
class DbalConsumerPositionModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(
            DbalConfiguration::class,
            $extensionObjects,
            DbalConfiguration::createWithDefaults()
        );

        if ($dbalConfiguration->isConsumerPositionTrackingEnabled()) {
            $messagingConfiguration->registerServiceDefinition(
                ConsumerPositionTracker::class,
                new Definition(DbalConsumerPositionTracker::class, [
                    new Reference($dbalConfiguration->getDbalDocumentStoreReference()),
                ])
            );
        }
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof DbalConfiguration;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
