<?php

namespace Ecotone\Amqp\Configuration;

use Ecotone\Amqp\AmqpInboundChannelAdapterBuilder;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
class AmqpMessageConsumerModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        /** @var AmqpMessageConsumerConfiguration $extensionObject */
        foreach ($extensionObjects as $extensionObject) {
            $messagingConfiguration->registerConsumer(
                AmqpInboundChannelAdapterBuilder::createWith(
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
        return $extensionObject instanceof AmqpMessageConsumerConfiguration;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::AMQP_PACKAGE;
    }
}
