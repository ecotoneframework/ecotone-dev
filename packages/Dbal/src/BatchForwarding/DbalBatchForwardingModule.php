<?php

declare(strict_types=1);

namespace Ecotone\Dbal\BatchForwarding;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\WithoutDatabaseTransaction;
use Ecotone\Messaging\Attribute\WithoutMessageCollector;
use Ecotone\Messaging\Channel\BatchForwardingConfiguration;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Endpoint\InboundChannelAdapter\InboundChannelAdapterBuilder;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\NullableMessageChannel;
use Ecotone\Messaging\Scheduling\EcotoneClockInterface;

#[ModuleAnnotation]
/**
 * licence Enterprise
 */
final class DbalBatchForwardingModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $channelBuilders = [];
        foreach (ExtensionObjectResolver::resolve(DbalBackedMessageChannelBuilder::class, $extensionObjects) as $channelBuilder) {
            $channelBuilders[$channelBuilder->getMessageChannelName()] = $channelBuilder;
        }

        $outboxesPerEndpointId = [];
        /** @var BatchForwardingConfiguration $batchForwardingConfiguration */
        foreach (ExtensionObjectResolver::resolve(BatchForwardingConfiguration::class, $extensionObjects) as $batchForwardingConfiguration) {
            $channelBuilder = $channelBuilders[$batchForwardingConfiguration->getChannelName()] ?? null;
            if (! $batchForwardingConfiguration->isEnabled() || $channelBuilder === null) {
                continue;
            }

            $messagingConfiguration->registerBatchForwardingSourceChannel($channelBuilder->getMessageChannelName());
            $outboxesPerEndpointId[$batchForwardingConfiguration->getEndpointId()][] = $this->createOutboxPublisherDefinition($channelBuilder, $batchForwardingConfiguration);
        }

        foreach ($outboxesPerEndpointId as $endpointId => $outboxPublishers) {
            $batchPublisherReference = 'dbal.batch_forwarding.' . $endpointId;
            $messagingConfiguration->registerServiceDefinition(
                $batchPublisherReference,
                new Definition(DbalBatchPublisher::class, [$outboxPublishers]),
            );
            $messagingConfiguration->registerConsumer(
                InboundChannelAdapterBuilder::create(
                    NullableMessageChannel::CHANNEL_NAME,
                    $batchPublisherReference,
                    $interfaceToCallRegistry->getFor(DbalBatchPublisher::class, 'publish'),
                )
                    ->withEndpointId((string) $endpointId)
                    ->withContinuousPolling()
                    ->withEndpointAnnotations([new AttributeDefinition(WithoutMessageCollector::class), new AttributeDefinition(WithoutDatabaseTransaction::class)]),
            );
        }
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof BatchForwardingConfiguration
            || $extensionObject instanceof DbalBackedMessageChannelBuilder;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }

    private function createOutboxPublisherDefinition(DbalBackedMessageChannelBuilder $channelBuilder, BatchForwardingConfiguration $batchForwardingConfiguration): Definition
    {
        $inboundChannelAdapter = $channelBuilder->getInboundChannelAdapter();

        return new Definition(DbalOutboxPublisher::class, [
            new Definition(CachedConnectionFactory::class, [
                new Definition(DbalReconnectableConnectionFactory::class, [
                    new Reference($inboundChannelAdapter->getConnectionReferenceName()),
                ]),
            ], 'createFor'),
            $channelBuilder->getMessageChannelName(),
            new Reference(ChannelResolver::class),
            new Reference(LoggingGateway::class),
            new Reference(EcotoneClockInterface::class),
            $batchForwardingConfiguration->getMaxForwardingBatchSize(),
            $batchForwardingConfiguration->getFinalFailureStrategy() ?? $inboundChannelAdapter->getFinalFailureStrategy(),
        ]);
    }
}
