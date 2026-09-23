<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Support\LicensingException;

use function array_map;
use function array_unique;
use function array_values;
use function implode;
use function sprintf;

#[ModuleAnnotation]
/**
 * licence Enterprise
 */
final class EventTaggingModule extends NoExternalConfigurationModule implements AnnotationModule
{
    /**
     * @param string[] $classesWithEventTags
     */
    private function __construct(private array $classesWithEventTags)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $classesWithEventTags = array_values(array_unique([
            ...$annotationRegistrationService->findAnnotatedClasses(EventTag::class),
            ...$annotationRegistrationService->findClassesWithAnnotatedProperties(EventTag::class),
            ...array_map(
                static fn ($annotatedMethod) => $annotatedMethod->getClassName(),
                $annotationRegistrationService->findAnnotatedMethods(EventTag::class),
            ),
        ]));

        return new self($classesWithEventTags);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if ($this->classesWithEventTags !== [] && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create(sprintf(
                'Dynamic Consistency Boundary (#[EventTag] used on %s) requires Ecotone Enterprise Licence.',
                implode(', ', $this->classesWithEventTags)
            ));
        }
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
