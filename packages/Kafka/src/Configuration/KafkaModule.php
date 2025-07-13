<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Configuration;

use Ecotone\AnnotationFinder\AnnotatedMethod;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Kafka\Attribute\KafkaConsumer;
use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Inbound\KafkaInboundChannelAdapterBuilder;
use Ecotone\Kafka\Outbound\KafkaOutboundChannelAdapterBuilder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
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
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\Support\LicensingException;

/**
 * licence Enterprise
 *
 * @link https://www.confluent.io/blog/how-to-send-messages-with-librdkafka/  Details about producing messages
 * @link https://github.com/confluentinc/librdkafka/wiki/FAQ#why-is-there-no-sync-produce-interface general faq on librdkafka
 */
#[ModuleAnnotation]
final class KafkaModule extends NoExternalConfigurationModule implements AnnotationModule
{
    /**
     * @param KafkaConsumer[] $kafkaConsumers
     * @param AnnotatedMethod[] $kafkaConsumersAnnotatedMethods
     */
    private function __construct(
        private array $kafkaConsumers,
        private array $kafkaConsumersAnnotatedMethods,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $kafkaConsumers = [];
        $kafkaConsumersAnnotatedMethods = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(KafkaConsumer::class) as $annotatedMethod) {
            /** @var KafkaConsumer $kafkaConsumer */
            $kafkaConsumer = $annotatedMethod->getAnnotationForMethod();

            $kafkaConsumers[$kafkaConsumer->getEndpointId()] = $kafkaConsumer;
            $kafkaConsumersAnnotatedMethods[] = $annotatedMethod;
        }

        return new self($kafkaConsumers, $kafkaConsumersAnnotatedMethods);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create('Kafka module is available only with Ecotone Enterprise licence.');
        }

        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());
        $consumerConfigurations = [];
        $topicConfigurations = [];
        $topicReferenceMapping = [];
        $publisherConfigurations = [];
        $kafkaBrokerConfigurations = [];
        $kafkaConsumers = $this->kafkaConsumers;

        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof KafkaMessageChannelBuilder) {
                $kafkaConsumers[$extensionObject->getMessageChannelName()] = new KafkaConsumer(
                    $extensionObject->getMessageChannelName(),
                    $extensionObject->topicName,
                    $extensionObject->groupId,
                );
                $publisherConfigurations[$extensionObject->getMessageChannelName()] = KafkaPublisherConfiguration::createWithDefaults(
                    $extensionObject->topicName,
                    MessagePublisher::class . '::' . $extensionObject->getMessageChannelName(),
                )
                    ->withHeaderMapper($extensionObject->getHeaderMapper());
            }
        }

        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof KafkaConsumerConfiguration) {
                $consumerConfigurations[$extensionObject->getEndpointId()] = $consumerConfigurations;
            } elseif ($extensionObject instanceof TopicConfiguration) {
                $topicConfigurations[$extensionObject->getTopicName()] = $extensionObject;
                $topicReferenceMapping[$extensionObject->referenceName] = $extensionObject->getTopicName();
            } elseif ($extensionObject instanceof KafkaPublisherConfiguration) {
                $publisherConfigurations[$this->getPublisherEndpointId($extensionObject->getReferenceName())] = $extensionObject;
                $this->registerMessagePublisher($messagingConfiguration, $extensionObject, $serviceConfiguration);
            }
        }

        foreach ($consumerConfigurations as $consumerConfiguration) {
            $kafkaBrokerConfigurations[$consumerConfiguration->getBrokerConfigurationReference()] = Reference::to($consumerConfiguration->getBrokerConfigurationReference());
        }
        foreach ($publisherConfigurations as $publisherConfiguration) {
            $kafkaBrokerConfigurations[$publisherConfiguration->getBrokerConfigurationReference()] = Reference::to($publisherConfiguration->getBrokerConfigurationReference());
        }

        foreach ($this->kafkaConsumersAnnotatedMethods as $kafkaConsumerAnnotatedMethod) {
            /** @var KafkaConsumer $kafkaConsumer */
            $kafkaConsumer = $kafkaConsumerAnnotatedMethod->getAnnotationForMethod();

            $messagingConfiguration->registerConsumer(
                KafkaInboundChannelAdapterBuilder::create(
                    endpointId: $kafkaConsumer->getEndpointId(),
                    requestChannelName: $kafkaConsumer->getEndpointId(),
                )
                    ->withFinalFailureStrategy($kafkaConsumer->getFinalFailureStrategy())
                    ->withEndpointAnnotations($kafkaConsumerAnnotatedMethod->getAllAnnotationDefinitions())
            );
        }

        $messagingConfiguration->registerServiceDefinition(
            KafkaAdmin::class,
            Definition::createFor(KafkaAdmin::class, [
                $kafkaConsumers,
                $consumerConfigurations,
                $topicConfigurations,
                $publisherConfigurations,
                $kafkaBrokerConfigurations,
                $topicReferenceMapping,
                Reference::to(LoggingGateway::class),
                $serviceConfiguration->isModulePackageEnabled(ModulePackageList::TEST_PACKAGE),
            ])
        );
    }

    public function canHandle($extensionObject): bool
    {
        return
            $extensionObject instanceof KafkaConsumerConfiguration
            || $extensionObject instanceof TopicConfiguration
            || $extensionObject instanceof KafkaPublisherConfiguration
            || $extensionObject instanceof KafkaMessageChannelBuilder
            || $extensionObject instanceof ServiceConfiguration;
    }

    public function getModulePackageName(): string
    {
        return 'kafka';
    }

    private function registerMessagePublisher(Configuration $messagingConfiguration, KafkaPublisherConfiguration $extensionObject, ServiceConfiguration $applicationConfiguration): void
    {
        $messagingConfiguration
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
                    $this->getPublisherEndpointId($extensionObject->getReferenceName())
                )
                    ->withInputChannelName($extensionObject->getReferenceName())
            );
    }

    private function getPublisherEndpointId(string $referenceName): string
    {
        return $referenceName . '.handler';
    }
}
