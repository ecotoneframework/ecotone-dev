<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging\Config;

use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\EventSourcing\EventStore\AppendStrategy\AppendStrategy;
use Ecotone\EventSourcing\EventStore\AppendStrategy\DynamicConsistencyBoundaryStrategy;
use Ecotone\EventSourcing\EventStore\AppendStrategy\StandardConsistencyBoundaryStrategy;
use Ecotone\EventSourcing\EventStore\Tag\EnterpriseInMemoryTagCollaborator;
use Ecotone\EventSourcing\EventStore\Tag\InMemoryTagCollaborator;
use Ecotone\EventSourcing\EventStore\Tag\OpenCoreInMemoryTagCollaborator;
use Ecotone\EventSourcing\Tagging\TagResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\LicenceDecider;

/**
 * licence Enterprise
 */
final class DynamicConsistencyBoundary
{
    private function __construct(
        private readonly bool $enabled,
        private readonly DynamicConsistencyBoundaryConfiguration $configuration,
    ) {
    }

    /**
     * @param object[] $extensionObjects
     */
    public static function resolveFrom(array $extensionObjects): self
    {
        return new self(
            ExtensionObjectResolver::contains(DynamicConsistencyBoundaryConfiguration::class, $extensionObjects),
            ExtensionObjectResolver::resolveUnique(DynamicConsistencyBoundaryConfiguration::class, $extensionObjects, DynamicConsistencyBoundaryConfiguration::createWithDefaults()),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return string[]
     */
    public function filterOnlyTagNames(): array
    {
        return $this->configuration->filterOnlyTagNames();
    }

    public function registerServicesForInMemoryStore(Configuration $messagingConfiguration): void
    {
        $messagingConfiguration->registerServiceDefinition(
            StandardConsistencyBoundaryStrategy::class,
            new Definition(StandardConsistencyBoundaryStrategy::class),
        );
        $messagingConfiguration->registerServiceDefinition(
            DynamicConsistencyBoundaryStrategy::class,
            new Definition(DynamicConsistencyBoundaryStrategy::class),
        );
        $messagingConfiguration->registerServiceDefinition(
            AppendStrategy::class,
            $this->definitionFor(AppendStrategy::class, StandardConsistencyBoundaryStrategy::class, DynamicConsistencyBoundaryStrategy::class),
        );

        $messagingConfiguration->registerServiceDefinition(
            OpenCoreInMemoryTagCollaborator::class,
            new Definition(OpenCoreInMemoryTagCollaborator::class),
        );
        $messagingConfiguration->registerServiceDefinition(
            EnterpriseInMemoryTagCollaborator::class,
            new Definition(EnterpriseInMemoryTagCollaborator::class, [Reference::to(TagResolver::class)]),
        );
        $messagingConfiguration->registerServiceDefinition(
            InMemoryTagCollaborator::class,
            $this->definitionFor(InMemoryTagCollaborator::class, OpenCoreInMemoryTagCollaborator::class, EnterpriseInMemoryTagCollaborator::class),
        );
    }

    public function definitionFor(string $className, string $openCoreServiceReference, string $enterpriseServiceReference): Definition|Reference
    {
        if (! $this->enabled) {
            return Reference::to($openCoreServiceReference);
        }

        return LicenceDecider::prepareDefinition($className, $openCoreServiceReference, $enterpriseServiceReference);
    }
}
