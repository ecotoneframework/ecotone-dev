<?php

namespace Ecotone\Dbal\Configuration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\Dbal\ExtensionObject\DbalMessagePublisherConfiguration;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\MessagePublisher;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Database\DbalTableManagerReference;
use Ecotone\Dbal\Database\EnqueueTableManager;
use Ecotone\Dbal\DbalOutboundChannelAdapterBuilder;
use Ecotone\Messaging\Channel\DeliveryConfirmation\Config\DeferredPublishingGatewayRegistration;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\BootstrapConfiguration;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ConsoleInvocationResolver;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeadersBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderValueBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class DbalPublisherModule implements AnnotationModule
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
        $registeredReferences = [];
        $applicationConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());

        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults());
        $consoleInvocationPrefix = ConsoleInvocationResolver::resolveConsolePrefix(ExtensionObjectResolver::resolveUnique(BootstrapConfiguration::class, $extensionObjects, BootstrapConfiguration::createWithDefaults()));
        foreach (self::connectionsWithQueues($extensionObjects) as $connectionReferenceName => $hasMessageQueues) {
            $messagingConfiguration->registerServiceDefinition(
                EnqueueTableManager::referenceFor($connectionReferenceName),
                new \Ecotone\Messaging\Config\Container\Definition(EnqueueTableManager::class, [
                    EnqueueTableManager::DEFAULT_TABLE_NAME,
                    $hasMessageQueues,
                    $consoleInvocationPrefix,
                    $connectionReferenceName,
                ])
            );
        }

        foreach (ExtensionObjectResolver::resolve(DbalMessagePublisherConfiguration::class, $extensionObjects) as $dbalPublisher) {
            if (in_array($dbalPublisher->getReferenceName(), $registeredReferences)) {
                throw ConfigurationException::create("Registering two publishers under same reference name {$dbalPublisher->getReferenceName()}. You need to create publisher with specific reference using `createWithReferenceName`.");
            }

            $registeredReferences[] = $dbalPublisher->getReferenceName();
            $mediaType              = $dbalPublisher->getOutputDefaultConversionMediaType() ? $dbalPublisher->getOutputDefaultConversionMediaType() : $applicationConfiguration->defaultSerializationMediaType;

            $messagingConfiguration = $messagingConfiguration
                ->registerGatewayBuilder(
                    GatewayProxyBuilder::create($dbalPublisher->getReferenceName(), MessagePublisher::class, 'send', $dbalPublisher->getReferenceName())
                        ->withParameterConverters(
                            [
                                GatewayPayloadBuilder::create('data'),
                                GatewayHeaderBuilder::create('sourceMediaType', MessageHeaders::CONTENT_TYPE),
                            ]
                        )
                )
                ->registerGatewayBuilder(
                    GatewayProxyBuilder::create($dbalPublisher->getReferenceName(), MessagePublisher::class, 'sendWithMetadata', $dbalPublisher->getReferenceName())
                        ->withParameterConverters(
                            [
                                GatewayPayloadBuilder::create('data'),
                                GatewayHeadersBuilder::create('metadata'),
                                GatewayHeaderBuilder::create('sourceMediaType', MessageHeaders::CONTENT_TYPE),
                            ]
                        )
                )
                ->registerGatewayBuilder(
                    GatewayProxyBuilder::create($dbalPublisher->getReferenceName(), MessagePublisher::class, 'convertAndSend', $dbalPublisher->getReferenceName())
                        ->withParameterConverters(
                            [
                                GatewayPayloadBuilder::create('data'),
                                GatewayHeaderValueBuilder::create(MessageHeaders::CONTENT_TYPE, MediaType::APPLICATION_X_PHP),
                            ]
                        )
                )
                ->registerGatewayBuilder(
                    GatewayProxyBuilder::create($dbalPublisher->getReferenceName(), MessagePublisher::class, 'convertAndSendWithMetadata', $dbalPublisher->getReferenceName())
                        ->withParameterConverters(
                            [
                                GatewayPayloadBuilder::create('data'),
                                GatewayHeadersBuilder::create('metadata'),
                                GatewayHeaderValueBuilder::create(MessageHeaders::CONTENT_TYPE, MediaType::APPLICATION_X_PHP),
                            ]
                        )
                )
                ->registerMessageHandler(
                    DbalOutboundChannelAdapterBuilder::create($dbalPublisher->getQueueName(), $dbalPublisher->getConnectionReference())
                        ->withEndpointId($dbalPublisher->getReferenceName() . '.handler')
                        ->withInputChannelName($dbalPublisher->getReferenceName())
                        ->withAutoDeclareOnSend($dbalPublisher->isAutoDeclareQueueOnSend())
                        ->withHeaderMapper($dbalPublisher->getHeaderMapper())
                        ->withDefaultConversionMediaType($mediaType)
                        ->withBatchPublishing($dbalPublisher->isBatchPublishingEnabled())
                );

            DeferredPublishingGatewayRegistration::registerFor($messagingConfiguration, $dbalPublisher->getReferenceName(), nonBlockingConfirmationEnabled: false);
        }
    }

    /**
     * @inheritDoc
     */
    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return array_map(
            static fn (string $connectionReferenceName) => new DbalTableManagerReference(EnqueueTableManager::referenceFor($connectionReferenceName), $connectionReferenceName),
            array_keys(self::connectionsWithQueues($serviceExtensions))
        );
    }

    /**
     * @param array<object> $extensionObjects
     * @return array<string, bool> connection reference name => whether a channel or publisher uses it
     */
    private static function connectionsWithQueues(array $extensionObjects): array
    {
        $connections = [DbalConnectionReference::DEFAULT => false];
        foreach (ExtensionObjectResolver::resolve(DbalBackedMessageChannelBuilder::class, $extensionObjects) as $channel) {
            $connections[$channel->connectionReferenceName] = true;
        }
        foreach (ExtensionObjectResolver::resolve(DbalMessagePublisherConfiguration::class, $extensionObjects) as $publisher) {
            $connections[$publisher->getConnectionReference()] = true;
        }

        return $connections;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
