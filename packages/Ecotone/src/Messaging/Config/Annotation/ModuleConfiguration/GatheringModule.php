<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\Gathering\GatheredGroupOperations;
use Ecotone\Messaging\Handler\Gathering\GatheredGroupStore;
use Ecotone\Messaging\Handler\Gathering\InMemoryGatheredGroupStore;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Support\LicensingException;

#[ModuleAnnotation]
/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 */
final class GatheringModule extends NoExternalConfigurationModule
{
    public function __construct(private array $gatherChannelNames)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $gatherChannelNames = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(Splitter::class) as $registration) {
            $splitter = $registration->getAnnotationForMethod();
            if ($splitter->gatherChannelName !== '') {
                $gatherChannelNames[] = $splitter->gatherChannelName;
            }
        }

        return new self($gatherChannelNames);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if ($this->gatherChannelNames !== [] && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            $gatherChannelName = $this->gatherChannelNames[0];
            throw LicensingException::create("Splitter gather channel '{$gatherChannelName}' requires an Ecotone Enterprise licence. Provide a licence key through EcotoneLite::bootstrapFlowTesting(licenceKey: ...), or remove gatherChannelName from #[Splitter].");
        }
        if ($this->gatherChannelNames !== []) {
            $messagingConfiguration->registerServiceDefinition(GatheredGroupStore::class, new Definition(InMemoryGatheredGroupStore::class));
            $messagingConfiguration->registerServiceDefinition(GatheredGroups::class, new Definition(GatheredGroupOperations::class, [new Reference(GatheredGroupStore::class)]));
        }
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
