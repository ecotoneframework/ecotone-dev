<?php
declare(strict_types=1);

namespace Ecotone\Redis\Configuration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeadersBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderValueBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Redis\RedisOutboundChannelAdapterBuilder;

#[ModuleAnnotation]
final class RedisMessagePublisherModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $configuration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());

        /** @var RedisMessagePublisherConfiguration $messagePublisher */
        foreach (ExtensionObjectResolver::resolve(RedisMessagePublisherConfiguration::class, $extensionObjects) as $messagePublisher) {
            $mediaType = $messagePublisher->getOutputDefaultConversionMediaType() ?: $serviceConfiguration->getDefaultSerializationMediaType();

            $configuration
                ->registerGatewayBuilder(
                    GatewayProxyBuilder::create($messagePublisher->getReferenceName(), MessagePublisher::class, 'send', $messagePublisher->getReferenceName())
                        ->withParameterConverters([
                            GatewayPayloadBuilder::create('data'),
                            GatewayHeaderBuilder::create('sourceMediaType', MessageHeaders::CONTENT_TYPE),
                        ])
                )
                ->registerGatewayBuilder(
                    GatewayProxyBuilder::create($messagePublisher->getReferenceName(), MessagePublisher::class, 'sendWithMetadata', $messagePublisher->getReferenceName())
                        ->withParameterConverters([
                            GatewayPayloadBuilder::create('data'),
                            GatewayHeadersBuilder::create('metadata'),
                            GatewayHeaderBuilder::create('sourceMediaType', MessageHeaders::CONTENT_TYPE),
                        ])
                )
                ->registerGatewayBuilder(
                    GatewayProxyBuilder::create($messagePublisher->getReferenceName(), MessagePublisher::class, 'convertAndSend', $messagePublisher->getReferenceName())
                        ->withParameterConverters([
                            GatewayPayloadBuilder::create('data'),
                            GatewayHeaderValueBuilder::create(MessageHeaders::CONTENT_TYPE, MediaType::APPLICATION_X_PHP),
                        ])
                )
                ->registerGatewayBuilder(
                    GatewayProxyBuilder::create($messagePublisher->getReferenceName(), MessagePublisher::class, 'convertAndSendWithMetadata', $messagePublisher->getReferenceName())
                        ->withParameterConverters([
                            GatewayPayloadBuilder::create('data'),
                            GatewayHeadersBuilder::create('metadata'),
                            GatewayHeaderValueBuilder::create(MessageHeaders::CONTENT_TYPE, MediaType::APPLICATION_X_PHP),
                        ])
                )
                ->registerMessageHandler(
                    RedisOutboundChannelAdapterBuilder::createWith($messagePublisher->getQueueName(), $messagePublisher->getConnectionReference())
                        ->withEndpointId($messagePublisher->getReferenceName() . '.handler')
                        ->withInputChannelName($messagePublisher->getReferenceName())
                        ->withAutoDeclareOnSend($messagePublisher->isAutoDeclareOnSend())
                        ->withHeaderMapper($messagePublisher->getHeaderMapper())
                        ->withDefaultConversionMediaType($mediaType)
                );
        }
    }

    public function canHandle($extensionObject): bool
    {
        return
            $extensionObject instanceof RedisMessagePublisherConfiguration
            || $extensionObject instanceof ServiceConfiguration;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::REDIS_PACKAGE;
    }
}
