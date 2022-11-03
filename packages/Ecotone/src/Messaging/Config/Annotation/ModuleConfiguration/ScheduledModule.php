<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Endpoint\ConsumerLifecycleBuilder;
use Ecotone\Messaging\Endpoint\InboundChannelAdapter\InboundChannelAdapterBuilder;

#[ModuleAnnotation]
class ScheduledModule extends ConsumerRegisterConfiguration
{
    /**
     * @inheritDoc
     */
    public static function getConsumerAnnotation(): string
    {
        return Scheduled::class;
    }

    /**
     * @inheritDoc
     */
    public static function createConsumerFrom(AnnotatedFinding $annotationRegistration): ConsumerLifecycleBuilder
    {
        /** @var Scheduled $annotation */
        $annotation = $annotationRegistration->getAnnotationForMethod();

        return InboundChannelAdapterBuilder::create($annotation->getRequestChannelName(), AnnotatedDefinitionReference::getReferenceFor($annotationRegistration), $annotationRegistration->getMethodName())
            ->withEndpointId($annotation->getEndpointId())
            ->withRequiredInterceptorNames($annotation->getRequiredInterceptorNames());
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
