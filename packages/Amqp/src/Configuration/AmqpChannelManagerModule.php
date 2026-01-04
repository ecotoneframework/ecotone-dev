<?php

declare(strict_types=1);

namespace Ecotone\Amqp\Configuration;

use Ecotone\Amqp\AmqpAdmin;
use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\AmqpChannelManager;
use Ecotone\Amqp\AmqpStreamChannelBuilder;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Channel\Manager\ChannelInitializationConfiguration;
use Ecotone\Messaging\Channel\Manager\ChannelManagerReference;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

#[ModuleAnnotation]
/**
 * Module responsible for registering AMQP channel managers.
 * Handles channel initialization configuration for AMQP message channels.
 *
 * licence Apache-2.0
 */
final class AmqpChannelManagerModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        // Get channel initialization configuration
        $channelInitConfig = ExtensionObjectResolver::resolveUnique(
            ChannelInitializationConfiguration::class,
            $extensionObjects,
            ChannelInitializationConfiguration::createWithDefaults()
        );

        $shouldAutoInitialize = $channelInitConfig->isAutomaticChannelInitializationEnabled();

        // Configure channel builders and register channel managers
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof AmqpBackedMessageChannelBuilder) {
                // Set declareOnStartup based on channel initialization configuration
                $extensionObject->getInboundChannelAdapter()->withDeclareOnStartup($shouldAutoInitialize);
                $extensionObject->getOutboundChannelAdapter()->withAutoDeclareOnSend($shouldAutoInitialize);

                $channelName = $extensionObject->getMessageChannelName();
                $queueName = $extensionObject->getQueueName();
                $connectionRef = $extensionObject->getInboundChannelAdapter()->getConnectionReferenceName();

                $managerRef = "amqp_channel_manager.{$channelName}";
                $messagingConfiguration->registerServiceDefinition(
                    $managerRef,
                    new Definition(AmqpChannelManager::class, [
                        $channelName,
                        $queueName,
                        new Reference($connectionRef),
                        new Reference(AmqpAdmin::REFERENCE_NAME),
                        $shouldAutoInitialize,
                        false, // isStreamChannel
                    ])
                );
            } elseif ($extensionObject instanceof AmqpStreamChannelBuilder) {
                // Set declareOnStartup based on channel initialization configuration
                $extensionObject->getInboundChannelAdapter()->withDeclareOnStartup($shouldAutoInitialize);
                $extensionObject->getOutboundChannelAdapter()->withAutoDeclareOnSend($shouldAutoInitialize);

                $channelName = $extensionObject->getMessageChannelName();
                $queueName = $extensionObject->queueName;
                $connectionRef = $extensionObject->getInboundChannelAdapter()->getConnectionReferenceName();

                $managerRef = "amqp_channel_manager.{$channelName}";
                $messagingConfiguration->registerServiceDefinition(
                    $managerRef,
                    new Definition(AmqpChannelManager::class, [
                        $channelName,
                        $queueName,
                        new Reference($connectionRef),
                        new Reference(AmqpAdmin::REFERENCE_NAME),
                        $shouldAutoInitialize,
                        true, // isStreamChannel
                    ])
                );
            }
        }
    }

    public function canHandle($extensionObject): bool
    {
        return
            $extensionObject instanceof AmqpBackedMessageChannelBuilder
            || $extensionObject instanceof AmqpStreamChannelBuilder
            || $extensionObject instanceof ChannelInitializationConfiguration;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $channelManagerReferences = [];

        // Create channel manager references for each AMQP channel
        foreach ($serviceExtensions as $extensionObject) {
            if ($extensionObject instanceof AmqpBackedMessageChannelBuilder) {
                $channelName = $extensionObject->getMessageChannelName();
                $channelManagerReferences[] = new ChannelManagerReference("amqp_channel_manager.{$channelName}");
            } elseif ($extensionObject instanceof AmqpStreamChannelBuilder) {
                $channelName = $extensionObject->getMessageChannelName();
                $channelManagerReferences[] = new ChannelManagerReference("amqp_channel_manager.{$channelName}");
            }
        }

        return $channelManagerReferences;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::AMQP_PACKAGE;
    }
}

