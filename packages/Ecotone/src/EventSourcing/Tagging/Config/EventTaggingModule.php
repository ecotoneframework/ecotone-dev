<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\EventSourcing\Tagging\EventTagRegistry;
use Ecotone\EventSourcing\Tagging\EventTagRegistryBuilder;
use Ecotone\EventSourcing\Tagging\TagResolver;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Support\LicensingException;

use function array_diff;
use function count;
use function implode;
use function sprintf;

#[ModuleAnnotation]
/**
 * licence Enterprise
 */
final class EventTaggingModule extends NoExternalConfigurationModule implements AnnotationModule
{
    /**
     * @param array<class-string, array<array{kind: string, name: string, member: ?string, value: ?string}>> $rawDefinitions
     */
    private function __construct(private array $rawDefinitions)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self(EventTagRegistryBuilder::buildRawDefinitions($annotationRegistrationService));
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $dynamicConsistencyBoundary = DynamicConsistencyBoundary::resolveFrom($extensionObjects);

        if ($dynamicConsistencyBoundary->isEnabled() && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create('Dynamic Consistency Boundary (DynamicConsistencyBoundaryConfiguration) requires Ecotone Enterprise Licence.');
        }

        $this->assertEveryFilterOnlyTagIsCarriedByAnEvent($dynamicConsistencyBoundary->filterOnlyTagNames());

        $messagingConfiguration->registerServiceDefinition(
            EventTagRegistry::class,
            new Definition(EventTagRegistry::class, [$this->rawDefinitions, $dynamicConsistencyBoundary->filterOnlyTagNames()], 'createWith'),
        );
        $messagingConfiguration->registerServiceDefinition(
            TagResolver::class,
            new Definition(TagResolver::class, [Reference::to(EventTagRegistry::class)]),
        );
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }

    /**
     * @param string[] $filterOnlyTagNames
     */
    private function assertEveryFilterOnlyTagIsCarriedByAnEvent(array $filterOnlyTagNames): void
    {
        $declaredTagNames = [];
        foreach ($this->rawDefinitions as $entries) {
            foreach ($entries as $entry) {
                $declaredTagNames[$entry['name']] = $entry['name'];
            }
        }

        $unknownTagNames = array_diff($filterOnlyTagNames, $declaredTagNames);
        if ($unknownTagNames !== []) {
            throw ConfigurationException::create(sprintf(
                "DynamicConsistencyBoundaryConfiguration::withFilterOnlyTags() names '%s', but no event carries %s. Tags declared with #[EventTag]: %s.",
                implode("', '", $unknownTagNames),
                count($unknownTagNames) === 1 ? 'that tag' : 'those tags',
                $declaredTagNames === [] ? 'none' : implode(', ', $declaredTagNames),
            ));
        }
    }
}
