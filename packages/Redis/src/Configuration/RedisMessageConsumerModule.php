<?php

declare(strict_types=1);

namespace Ecotone\Redis\Configuration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Redis\RedisInboundChannelAdapterBuilder;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class RedisMessageConsumerModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        foreach (ExtensionObjectResolver::resolve(RedisMessageConsumerConfiguration::class, $extensionObjects) as $extensionObject) {
            $messagingConfiguration->registerConsumer(
                RedisInboundChannelAdapterBuilder::createWith(
                    $extensionObject->getEndpointId(),
                    $extensionObject->getQueueName(),
                    $extensionObject->getEndpointId(),
                    $extensionObject->getConnectionReferenceName()
                )
                    ->withDeclareOnStartup($extensionObject->isDeclaredOnStartup())
                    ->withHeaderMapper($extensionObject->getHeaderMapper())
                    ->withReceiveTimeout($extensionObject->getReceiveTimeoutInMilliseconds())
            );
        }
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::REDIS_PACKAGE;
    }
}
