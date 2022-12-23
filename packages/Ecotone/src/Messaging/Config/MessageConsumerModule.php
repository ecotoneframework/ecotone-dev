<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

final class MessageConsumerModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        // TODO: Implement create() method.
    }

    public function prepare(Configuration $configuration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        // TODO: Implement prepare() method.
    }

    public function canHandle($extensionObject): bool
    {
        // TODO: Implement canHandle() method.
    }

    public function getModulePackageName(): string
    {
        // TODO: Implement getModulePackageName() method.
    }
}