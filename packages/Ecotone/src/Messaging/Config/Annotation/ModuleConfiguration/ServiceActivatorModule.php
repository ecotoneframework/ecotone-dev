<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\Saga;
use Ecotone\Api\Attribute\ServiceActivator;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Support\InvalidArgumentException;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
class ServiceActivatorModule extends MessageHandlerRegisterConfiguration
{
    /**
     * @inheritDoc
     */
    public static function createMessageHandlerFrom(AnnotatedFinding $annotationRegistration, InterfaceToCallRegistry $interfaceToCallRegistry): MessageHandlerBuilderWithParameterConverters
    {
        if ($annotationRegistration->hasClassAnnotation(Saga::class) || $annotationRegistration->hasClassAnnotation(Aggregate::class)) {
            throw InvalidArgumentException::create("Message Handler or Service Activator works as stateless Handler and can't be used on Aggregate or Saga");
        }

        /** @var ServiceActivator $annotation */
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
        return ServiceActivator::class;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
