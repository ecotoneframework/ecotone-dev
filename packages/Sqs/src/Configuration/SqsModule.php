<?php

declare(strict_types=1);

namespace Ecotone\Sqs\Configuration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Sqs\SqsBackedMessageChannelBuilder;

#[ModuleAnnotation]
/**
 * Module for handling SQS message channel builders.
 *
 * licence Apache-2.0
 */
final class SqsModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        // This module now only handles basic SQS channel builder registration
        // Channel manager registration is handled by SqsChannelManagerModule
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof SqsBackedMessageChannelBuilder;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::SQS_PACKAGE;
    }
}
