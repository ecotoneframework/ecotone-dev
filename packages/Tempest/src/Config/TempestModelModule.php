<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class TempestModelModule extends NoExternalConfigurationModule implements AnnotationModule
{
    /**
     * @param class-string[] $aggregateClasses
     */
    private function __construct(private array $aggregateClasses)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self($annotationRegistrationService->findAnnotatedClasses(Aggregate::class));
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        TempestModelVersionGuard::assertNoVersionedTempestModel($this->aggregateClasses, $interfaceToCallRegistry);
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::TEMPEST_PACKAGE;
    }
}
