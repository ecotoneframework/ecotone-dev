<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\Parameter\Fetch;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Support\LicensingException;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
class FetchAggregateModule implements AnnotationModule
{
    private function __construct()
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function canHandle($extensionObject): bool
    {
        return false;
    }

    public function getModuleExtensions(array $serviceExtensions): array
    {
        return [];
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (!$messagingConfiguration->isRunningForEnterpriseLicence()) {
            // Check if any method uses the FetchAggregate attribute
            foreach ($interfaceToCallRegistry->getAllInterfaces() as $interfaceToCall) {
                foreach ($interfaceToCall->getInterfaceParameters() as $parameter) {
                    if ($parameter->hasAnnotation(Fetch::class)) {
                        throw LicensingException::create('FetchAggregate attribute is available as part of Ecotone Enterprise.');
                    }
                }
            }
        }
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
