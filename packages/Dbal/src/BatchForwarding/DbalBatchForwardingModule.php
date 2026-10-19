<?php

declare(strict_types=1);

namespace Ecotone\Dbal\BatchForwarding;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\Attribute\WithoutMessageCollector;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\OutboxForwardingMessageChannel;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Dbal\Tracing\FlowTrackingScope;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Endpoint\InboundChannelAdapter\InboundChannelAdapterBuilder;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\NullableMessageChannel;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

#[ModuleAnnotation]
/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
                    new Reference(EcotoneClockInterface::class),
                ]),
            ], 'createFor'),
            $channelBuilder->getMessageChannelName(),
            new Reference(ChannelResolver::class),
            new Reference(LoggingGateway::class),
            new Reference(EcotoneClockInterface::class),
            $outboxForwardingChannel->getMaxForwardingBatchSize(),
            $outboxForwardingChannel->getFinalFailureStrategy() ?? $inboundChannelAdapter->getFinalFailureStrategy(),
            new Reference(MessageBuilder::class),
            new Reference(FlowTrackingScope::class),
        ]);
    }
}
