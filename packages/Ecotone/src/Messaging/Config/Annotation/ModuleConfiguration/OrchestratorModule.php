<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\Orchestrator;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Saga;

#[ModuleAnnotation]
/**
 * licence Enterprise
 */
class OrchestratorModule implements AnnotationModule
{
    /**
     * @var MessageHandlerBuilderWithParameterConverters[]
     */
    private array $messageHandlerBuilders;

    private function __construct(array $messageHandlerBuilders)
    {
        $this->messageHandlerBuilders = $messageHandlerBuilders;
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $messageHandlerBuilders = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(Orchestrator::class) as $annotationRegistration) {
            $messageHandlerBuilders[] = self::createMessageHandlerFrom($annotationRegistration, $interfaceToCallRegistry);
        }

        return new self($messageHandlerBuilders);
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $this->verifyOrchestratorLicense($messagingConfiguration);

        foreach ($this->messageHandlerBuilders as $messageHandlerBuilder) {
            $messagingConfiguration->registerMessageHandler($messageHandlerBuilder);
        }
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return false;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    public static function createMessageHandlerFrom(AnnotatedFinding $annotationRegistration, InterfaceToCallRegistry $interfaceToCallRegistry): MessageHandlerBuilderWithParameterConverters
    {
        if ($annotationRegistration->hasClassAnnotation(Saga::class) || $annotationRegistration->hasClassAnnotation(Aggregate::class)) {
            throw InvalidArgumentException::create("Orchestrator works as stateless Handler and can't be used on Aggregate or Saga");
        }

        $interfaceToCall = $interfaceToCallRegistry->getFor($annotationRegistration->getClassName(), $annotationRegistration->getMethodName());

        // Validate return type - only array is allowed
        $returnType = $interfaceToCall->getReturnType();
        if ($returnType && !$returnType->isVoid()) {
            $returnTypeName = $returnType->toString();
            if ($returnTypeName !== 'array') {
                throw InvalidArgumentException::create(
                    sprintf(
                        "Orchestrator method %s::%s must return array of strings, but returns %s",
                        $annotationRegistration->getClassName(),
                        $annotationRegistration->getMethodName(),
                        $returnTypeName
                    )
                );
            }
        }

        /** @var Orchestrator $annotation */
        $annotation = $annotationRegistration->getAnnotationForMethod();

        return ServiceActivatorBuilder::create(AnnotatedDefinitionReference::getReferenceFor($annotationRegistration), $interfaceToCall)
            ->withEndpointId($annotation->getEndpointId())
            ->withInputChannelName($annotation->getInputChannelName())
            ->withChangingHeaders(true, MessageHeaders::ROUTING_SLIP);
    }

    private function verifyOrchestratorLicense(Configuration $messagingConfiguration): void
    {
        if ($messagingConfiguration->isRunningForEnterpriseLicence()) {
            return;
        }

        if (!empty($this->messageHandlerBuilders)) {
            throw LicensingException::create("Orchestrator attribute is available only with Ecotone Enterprise licence. This functionality requires enterprise mode to ensure proper workflow orchestration capabilities.");
        }
    }
}
