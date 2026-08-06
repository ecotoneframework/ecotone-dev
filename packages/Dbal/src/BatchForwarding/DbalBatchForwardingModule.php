<?php

declare(strict_types=1);

namespace Ecotone\Dbal\BatchForwarding;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Dbal\OutboxForwardingMessageChannel;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\WithoutDatabaseTransaction;
use Ecotone\Messaging\Attribute\WithoutMessageCollector;
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
use Ecotone\Messaging\Support\Assert;

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

        $forwardingChannelsPerSource = [];
        /** @var OutboxForwardingMessageChannel $outboxForwardingChannel */
        foreach (ExtensionObjectResolver::resolve(OutboxForwardingMessageChannel::class, $extensionObjects) as $outboxForwardingChannel) {
            $sourceChannelName = $outboxForwardingChannel->getSourceChannelName();
            if (isset($forwardingChannelsPerSource[$sourceChannelName])) {
                $alreadyRegistered = $forwardingChannelsPerSource[$sourceChannelName];
                Assert::isTrue(
                    $alreadyRegistered->getMaxForwardingBatchSize() === $outboxForwardingChannel->getMaxForwardingBatchSize()
                    && $alreadyRegistered->getEndpointId() === $outboxForwardingChannel->getEndpointId()
                    && $alreadyRegistered->getFinalFailureStrategy() === $outboxForwardingChannel->getFinalFailureStrategy(),
                    "Outbox forwarding Message Channels sharing source `{$sourceChannelName}` must configure the same batch size, endpoint id and failure strategy.",
                );

                continue;
            }
            $forwardingChannelsPerSource[$sourceChannelName] = $outboxForwardingChannel;
        }

        $outboxesPerEndpointId = [];
        foreach ($forwardingChannelsPerSource as $sourceChannelName => $outboxForwardingChannel) {
            $channelBuilder = $channelBuilders[$sourceChannelName] ?? null;
            if ($channelBuilder === null) {
                continue;
            }

            $messagingConfiguration->registerBatchForwardingSourceChannel($sourceChannelName);
            $outboxesPerEndpointId[$outboxForwardingChannel->getEndpointId()][] = $this->createOutboxPublisherDefinition($channelBuilder, $outboxForwardingChannel);
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
        return $extensionObject instanceof OutboxForwardingMessageChannel
            || $extensionObject instanceof DbalBackedMessageChannelBuilder;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }

    private function createOutboxPublisherDefinition(DbalBackedMessageChannelBuilder $channelBuilder, OutboxForwardingMessageChannel $outboxForwardingChannel): Definition
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
            $outboxForwardingChannel->getMaxForwardingBatchSize(),
            $outboxForwardingChannel->getFinalFailureStrategy() ?? $inboundChannelAdapter->getFinalFailureStrategy(),
        ]);
    }
}
