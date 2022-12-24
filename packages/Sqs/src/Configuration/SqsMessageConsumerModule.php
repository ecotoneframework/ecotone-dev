<?php

declare(strict_types=1);

namespace Ecotone\Sqs\Configuration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Sqs\SqsInboundChannelAdapterBuilder;

#[ModuleAnnotation]
final class SqsMessageConsumerModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $configuration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        /** @var SqsMessageConsumerConfiguration $extensionObject */
        foreach ($extensionObjects as $extensionObject) {
            $configuration->registerConsumer(
                SqsInboundChannelAdapterBuilder::createWith(
                    $extensionObject->getEndpointId(),
                    $extensionObject->getQueueName(),
                    $extensionObject->getEndpointId(),
                    $extensionObject->getConnectionReferenceName()
                )
                    ->withHeaderMapper($extensionObject->getHeaderMapper())
                    ->withReceiveTimeout($extensionObject->getReceiveTimeoutInMilliseconds())
            );
        }
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof SqsMessageConsumerConfiguration;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::SQS_PACKAGE;
    }
}
