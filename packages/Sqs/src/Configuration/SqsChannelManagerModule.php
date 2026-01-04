<?php

declare(strict_types=1);

namespace Ecotone\Sqs\Configuration;

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
use Ecotone\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Sqs\SqsChannelManager;

#[ModuleAnnotation]
/**
 * Module responsible for registering SQS channel managers.
 * Handles channel initialization configuration for SQS message channels.
 *
 * licence Apache-2.0
 */
final class SqsChannelManagerModule extends NoExternalConfigurationModule implements AnnotationModule
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
            if ($extensionObject instanceof SqsBackedMessageChannelBuilder) {
                // Set declareOnStartup based on channel initialization configuration
                $extensionObject->getInboundChannelAdapter()->withDeclareOnStartup($shouldAutoInitialize);
                $extensionObject->getOutboundChannelAdapter()->withAutoDeclareOnSend($shouldAutoInitialize);

                $channelName = $extensionObject->getMessageChannelName();
                $queueName = $channelName; // For SQS, queue name is same as channel name
                $connectionRef = $extensionObject->getInboundChannelAdapter()->getConnectionReferenceName();

                $managerRef = "sqs_channel_manager.{$channelName}";
                $messagingConfiguration->registerServiceDefinition(
                    $managerRef,
                    new Definition(SqsChannelManager::class, [
                        $channelName,
                        $queueName,
                        new Reference($connectionRef),
                        $shouldAutoInitialize,
                    ])
                );
            }
        }
    }

    public function canHandle($extensionObject): bool
    {
        return
            $extensionObject instanceof SqsBackedMessageChannelBuilder
            || $extensionObject instanceof ChannelInitializationConfiguration;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $channelManagerReferences = [];

        // Create channel manager references for each SQS channel
        foreach ($serviceExtensions as $extensionObject) {
            if ($extensionObject instanceof SqsBackedMessageChannelBuilder) {
                $channelName = $extensionObject->getMessageChannelName();
                $channelManagerReferences[] = new ChannelManagerReference("sqs_channel_manager.{$channelName}");
            }
        }

        return $channelManagerReferences;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::SQS_PACKAGE;
    }
}

