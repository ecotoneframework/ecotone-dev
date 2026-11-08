<?php

namespace Ecotone\Amqp\Publisher;

use Ecotone\Amqp\AmqpOutboundChannelAdapterBuilder;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Amqp\AmqpMessagePublisherConfiguration;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\MessagePublisher;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Channel\DeliveryConfirmation\Config\DeferredPublishingGatewayRegistration;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeadersBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderValueBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class AmqpMessagePublisherModule implements AnnotationModule
{
    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $applicationConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());
        ;

        /** @var AmqpMessagePublisherConfiguration $amqpPublisher */
        foreach (ExtensionObjectResolver::resolve(AmqpMessagePublisherConfiguration::class, $extensionObjects) as $amqpPublisher) {
            $mediaType = $amqpPublisher->getOutputDefaultConversionMediaType() ? $amqpPublisher->getOutputDefaultConversionMediaType() : $applicationConfiguration->defaultSerializationMediaType;

            $messagingConfiguration = $messagingConfiguration
                ->registerGatewayBuilder(
                    GatewayProxyBuilder::create($amqpPublisher->getReferenceName(), MessagePublisher::class, 'send', $amqpPublisher->getReferenceName())
                        ->withParameterConverters([
                            GatewayPayloadBuilder::create('data'),
                            GatewayHeaderBuilder::create('sourceMediaType', MessageHeaders::CONTENT_TYPE),
                        ])
                )
                ->registerGatewayBuilder(
                    GatewayProxyBuilder::create($amqpPublisher->getReferenceName(), MessagePublisher::class, 'sendWithMetadata', $amqpPublisher->getReferenceName())
                        ->withParameterConverters([
                            GatewayPayloadBuilder::create('data'),
                            GatewayHeadersBuilder::create('metadata'),
                            GatewayHeaderBuilder::create('sourceMediaType', MessageHeaders::CONTENT_TYPE),
                        ])
                )
                ->registerGatewayBuilder(
                    GatewayProxyBuilder::create($amqpPublisher->getReferenceName(), MessagePublisher::class, 'convertAndSend', $amqpPublisher->getReferenceName())
                        ->withParameterConverters([
                            GatewayPayloadBuilder::create('data'),
                            GatewayHeaderValueBuilder::create(MessageHeaders::CONTENT_TYPE, MediaType::APPLICATION_X_PHP),
                        ])
                )
                ->registerGatewayBuilder(
                    GatewayProxyBuilder::create($amqpPublisher->getReferenceName(), MessagePublisher::class, 'convertAndSendWithMetadata', $amqpPublisher->getReferenceName())
                        ->withParameterConverters([
                            GatewayPayloadBuilder::create('data'),
                            GatewayHeadersBuilder::create('metadata'),
                            GatewayHeaderValueBuilder::create(MessageHeaders::CONTENT_TYPE, MediaType::APPLICATION_X_PHP),
                        ])
                )
                ->registerMessageHandler(
                    AmqpOutboundChannelAdapterBuilder::create($amqpPublisher->getExchangeName(), $amqpPublisher->getConnectionReference())
                        ->withEndpointId($amqpPublisher->getReferenceName() . '.handler')
                        ->withInputChannelName($amqpPublisher->getReferenceName())
                        ->withDefaultPersistentMode($amqpPublisher->getDefaultPersistentDelivery())
                        ->withAutoDeclareOnSend($amqpPublisher->isAutoDeclareOnSend())
                        ->withHeaderMapper($amqpPublisher->getHeaderMapper())
                        ->withDefaultRoutingKey($amqpPublisher->getDefaultRoutingKey())
                        ->withRoutingKeyFromHeader($amqpPublisher->getRoutingKeyFromHeader())
                        ->withDefaultConversionMediaType($mediaType)
                        ->withHighThroughputPublishing($amqpPublisher->isBatchPublishingEnabled(), $amqpPublisher->isNonBlockingConfirmationEnabled(), $amqpPublisher->getConfirmationTimeout())
                        ->withPublishingChannelName($amqpPublisher->getReferenceName())
                );

            DeferredPublishingGatewayRegistration::registerFor($messagingConfiguration, $amqpPublisher->getReferenceName(), $amqpPublisher->isNonBlockingConfirmationEnabled());
        }
    }

    /**
     * @inheritDoc
     */
    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }

        return [PulseFeature::messagePublishers(
            package: ModulePackageList::AMQP_PACKAGE,
            broker: 'RabbitMQ',
            publishers: array_map(
                static fn (AmqpMessagePublisherConfiguration $publisher): string => sprintf(
                    "%s: exchange '%s'%s%s%s",
                    PulseFeature::shortNameOf($publisher->getReferenceName()),
                    $publisher->getExchangeName(),
                    $publisher->getDefaultRoutingKey() === '' ? '' : sprintf(", routing key '%s'", $publisher->getDefaultRoutingKey()),
                    $publisher->getDefaultPersistentDelivery() ? ', persistent' : '',
                    $publisher->isBatchPublishingEnabled() ? ', batch publishing' : '',
                ),
                ExtensionObjectResolver::resolve(AmqpMessagePublisherConfiguration::class, $serviceExtensions),
            ),
            configuration: "AmqpMessagePublisherConfiguration::create(MessagePublisher::class, exchangeName: 'orders')",
        )];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::AMQP_PACKAGE;
    }
}
