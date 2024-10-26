<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\Amqp\AmqpOutboundChannelAdapterBuilder;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Kafka\Outbound\KafkaOutboundChannelAdapterBuilder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
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
use Ecotone\Messaging\Support\LicensingException;

/**
 * licence Enterprise
 */
#[ModuleAnnotation]
final class KafkaModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (!$messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create("Kafka module is available only with Ecotone Enterprise licence.");
        }

        $applicationConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());
        $consumerConfigurations = [];
        $topicConfigurations = [];

        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof ConsumerConfiguration) {
                $consumerConfigurations[$extensionObject->getEndpointId()] = $consumerConfigurations;
            } else if ($extensionObject instanceof TopicConfiguration) {
                $topicConfigurations[$extensionObject->getTopicName()] = $topicConfigurations;
            } else if ($extensionObject instanceof KafkaPublisherConfiguration) {
                $messagingConfiguration = $messagingConfiguration
                    ->registerGatewayBuilder(
                        GatewayProxyBuilder::create($extensionObject->getReferenceName(), MessagePublisher::class, 'send', $extensionObject->getReferenceName())
                            ->withParameterConverters([
                                GatewayPayloadBuilder::create('data'),
                                GatewayHeaderBuilder::create('sourceMediaType', MessageHeaders::CONTENT_TYPE),
                            ])
                    )
                    ->registerGatewayBuilder(
                        GatewayProxyBuilder::create($extensionObject->getReferenceName(), MessagePublisher::class, 'sendWithMetadata', $extensionObject->getReferenceName())
                            ->withParameterConverters([
                                GatewayPayloadBuilder::create('data'),
                                GatewayHeadersBuilder::create('metadata'),
                                GatewayHeaderBuilder::create('sourceMediaType', MessageHeaders::CONTENT_TYPE),
                            ])
                    )
                    ->registerGatewayBuilder(
                        GatewayProxyBuilder::create($extensionObject->getReferenceName(), MessagePublisher::class, 'convertAndSend', $extensionObject->getReferenceName())
                            ->withParameterConverters([
                                GatewayPayloadBuilder::create('data'),
                                GatewayHeaderValueBuilder::create(MessageHeaders::CONTENT_TYPE, MediaType::APPLICATION_X_PHP),
                            ])
                    )
                    ->registerGatewayBuilder(
                        GatewayProxyBuilder::create($extensionObject->getReferenceName(), MessagePublisher::class, 'convertAndSendWithMetadata', $extensionObject->getReferenceName())
                            ->withParameterConverters([
                                GatewayPayloadBuilder::create('data'),
                                GatewayHeadersBuilder::create('metadata'),
                                GatewayHeaderValueBuilder::create(MessageHeaders::CONTENT_TYPE, MediaType::APPLICATION_X_PHP),
                            ])
                    )
                    ->registerMessageHandler(
                        KafkaOutboundChannelAdapterBuilder::create(
                            $extensionObject,
                            $extensionObject->getOutputDefaultConversionMediaType() ?: $applicationConfiguration->getDefaultSerializationMediaType()
                        )
                            ->withEndpointId($extensionObject->getReferenceName() . '.handler')
                            ->withInputChannelName($extensionObject->getReferenceName())
                    );
            }
        }

        $messagingConfiguration->registerServiceDefinition(
            KafkaAdmin::class,
            Definition::createFor(KafkaAdmin::class, [
                $consumerConfigurations,
                $topicConfigurations,
            ])
        );
    }

    public function canHandle($extensionObject): bool
    {
        return
            $extensionObject instanceof ConsumerConfiguration
            || $extensionObject instanceof TopicConfiguration
            || $extensionObject instanceof KafkaPublisherConfiguration;
    }

    public function getModulePackageName(): string
    {
        return "kafka";
    }
}