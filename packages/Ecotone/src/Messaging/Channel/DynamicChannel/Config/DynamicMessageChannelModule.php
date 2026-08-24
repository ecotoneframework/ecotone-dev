<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\DynamicChannel\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\ModuleAnnotation;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Support\LicensingException;

#[ModuleAnnotation]
/**
 * licence Enterprise
 */
final class DynamicMessageChannelModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $dynamicMessageChannelBuilders = ExtensionObjectResolver::resolve(DynamicMessageChannelBuilder::class, $extensionObjects);

        if (! $messagingConfiguration->isRunningForEnterpriseLicence() && ! empty($dynamicMessageChannelBuilders)) {
            throw LicensingException::create('Dynamic message channels are available only as part of Ecotone Enterprise.');
        }
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
