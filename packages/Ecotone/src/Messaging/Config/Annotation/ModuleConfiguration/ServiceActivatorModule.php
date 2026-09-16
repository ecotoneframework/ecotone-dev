<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Aggregate;
use Ecotone\Api\InternalHandler;
use Ecotone\Api\ModuleAnnotation;
use Ecotone\Api\Saga;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\LicensingException;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
class ServiceActivatorModule extends MessageHandlerRegisterConfiguration
{
    /**
     * @var AnnotatedFinding[]
     */
    private array $changingHeadersFindings = [];

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $instance = parent::create($annotationRegistrationService, $interfaceToCallRegistry);

        foreach ($annotationRegistrationService->findAnnotatedMethods(static::getMessageHandlerAnnotation()) as $annotationRegistration) {
            /** @var InternalHandler $annotation */
            $annotation = $annotationRegistration->getAnnotationForMethod();
            if ($annotation->isChangingHeaders()) {
                $instance->changingHeadersFindings[] = $annotationRegistration;
            }
        }

        return $instance;
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if ($this->changingHeadersFindings && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            $firstFinding = $this->changingHeadersFindings[0];
            throw LicensingException::create("{$firstFinding->getClassName()}::{$firstFinding->getMethodName()} is using changingHeaders, which is available only with Ecotone Enterprise licence. See https://docs.ecotone.tech/enterprise for details.");
        }

        parent::prepare($messagingConfiguration, $extensionObjects, $moduleReferenceSearchService, $interfaceToCallRegistry);
    }

    /**
     * @inheritDoc
     */
    public static function createMessageHandlerFrom(AnnotatedFinding $annotationRegistration, InterfaceToCallRegistry $interfaceToCallRegistry): MessageHandlerBuilderWithParameterConverters
    {
        if ($annotationRegistration->hasClassAnnotation(Saga::class) || $annotationRegistration->hasClassAnnotation(Aggregate::class)) {
            throw InvalidArgumentException::create("Message Handler or Service Activator works as stateless Handler and can't be used on Aggregate or Saga");
        }

        /** @var InternalHandler $annotation */
        $annotation = $annotationRegistration->getAnnotationForMethod();

        return ServiceActivatorBuilder::create(AnnotatedDefinitionReference::getReferenceFor($annotationRegistration), $interfaceToCallRegistry->getFor($annotationRegistration->getClassName(), $annotationRegistration->getMethodName()))
            ->withEndpointId($annotation->getEndpointId())
            ->withRequiredReply($annotation->isRequiresReply())
            ->withOutputMessageChannel($annotation->getOutputChannelName())
            ->withInputChannelName($annotation->getInputChannelName())
            ->withRequiredInterceptorNames($annotation->getRequiredInterceptorNames())
            ->withChangingHeaders($annotation->isChangingHeaders());
    }

    /**
     * @inheritDoc
     */
    public static function getMessageHandlerAnnotation(): string
    {
        return InternalHandler::class;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
