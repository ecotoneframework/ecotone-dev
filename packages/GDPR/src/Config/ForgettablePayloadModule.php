<?php

declare(strict_types=1);

namespace Ecotone\GDPR\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\EventSourcing\EventStore;
use Ecotone\GDPR\ForgettablePayload\ForgettablePayloadConfiguration;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

/**
 * licence Apache-2.0
 */
#[ModuleAnnotation]
final class ForgettablePayloadModule extends NoExternalConfigurationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(
        Configuration $messagingConfiguration,
        array $extensionObjects,
        ModuleReferenceSearchService $moduleReferenceSearchService,
        InterfaceToCallRegistry $interfaceToCallRegistry
    ): void {
        if (! ExtensionObjectResolver::contains(ForgettablePayloadConfiguration::class, $extensionObjects)) {
            return;
        }
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof ForgettablePayloadConfiguration;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::GDPR_PACKAGE;
    }
}
