<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\LicenceDecider;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

#[ModuleAnnotation]
final class MessageFlowModule implements AnnotationModule
{
    private function __construct(private readonly AnnotationFinder $annotationFinder)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self($annotationRegistrationService);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $messagingConfiguration->registerServiceDefinition(OpenCoreMessageFlowGraphBuilder::class, new Definition(OpenCoreMessageFlowGraphBuilder::class));
        if ($messagingConfiguration->isRunningForEnterpriseLicence()) {
            $declared = MessageFlowDiscovery::discover($this->annotationFinder, $interfaceToCallRegistry, $extensionObjects);
            $messagingConfiguration->registerServiceDefinition(EnterpriseMessageFlowGraphBuilder::class, new Definition(EnterpriseMessageFlowGraphBuilder::class, [
                $declared->graph->getDefinition(),
                $declared->collaborators,
                $declared->namedEvents,
                new Definition(HandlerSourceScanner::class),
            ]));
            $messagingConfiguration->registerServiceDefinition(MessageFlowGraphBuilder::class, LicenceDecider::prepareDefinition(MessageFlowGraphBuilder::class, OpenCoreMessageFlowGraphBuilder::class, EnterpriseMessageFlowGraphBuilder::class));
        } else {
            $messagingConfiguration->registerServiceDefinition(MessageFlowGraphBuilder::class, Reference::to(OpenCoreMessageFlowGraphBuilder::class));
        }
        $messagingConfiguration->registerServiceDefinition(MessageFlowConsoleCommands::class, new Definition(MessageFlowConsoleCommands::class));
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
