<?php

namespace Ecotone\Messaging\Config;

use Closure;
use Ecotone\Messaging\Channel\EventDrivenChannelInterceptorAdapter;
use Ecotone\Messaging\Channel\PollableChannelInterceptorAdapter;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Conversion\AutoCollectionConversionService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\Assert;

class MessagingComponentsFactory
{

    public function __construct(
        private PreparedConfiguration $configuration,
        private ReferenceSearchService $referenceSearchService,
    )
    {
    }

    public function buildChannel(string $channelName): MessageChannel
    {
        $channelsBuilder = $this->configuration->getChannelBuilders()[$channelName];
        $channelInterceptorBuilders = $this->configuration->getChannelInterceptorsBuilders()[$channelName] ?? [];
        $messageChannel = $channelsBuilder->build($this->referenceSearchService);
        $interceptorsForChannel = array_map(fn ($channelInterceptorBuilder) => $channelInterceptorBuilder->build($this->referenceSearchService), $channelInterceptorBuilders);

        if ($interceptorsForChannel) {
            $messageChannel = $messageChannel instanceof PollableChannel
                ? new PollableChannelInterceptorAdapter($messageChannel, $interceptorsForChannel)
                : new EventDrivenChannelInterceptorAdapter($messageChannel, $interceptorsForChannel);
        }

        return $messageChannel;
    }

    public function buildGateway(string $referenceName, string $methodName, ChannelResolver $channelResolver): NonProxyGateway
    {
        $gatewayBuilder = $this->configuration->getGatewayBuilders()[$referenceName][$methodName];
        return $gatewayBuilder->buildWithoutProxyObject($this->referenceSearchService, $channelResolver);
    }

    public function getInterfaceToCallRegistry(): InterfaceToCallRegistry
    {
        return $this->configuration->getInterfaceToCallRegistry();
    }

    public function buildConversionService(): ConversionService
    {
        $converters = [];
        foreach ($this->configuration->getConverterBuilders() as $converterBuilder) {
            $converters[] = $converterBuilder->build($this->referenceSearchService);
        }

        return AutoCollectionConversionService::createWith($converters);
    }

    public function getReferenceToRegister(string $referenceName): object
    {
        return $this->configuration->getReferencesToRegister()[$referenceName];
    }

    public function buildMessageHandler(string $endpointId, ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): MessageHandler
    {
        $messageHandlerBuilder = $this->configuration->getMessageHandlerBuilders()[$endpointId];
        return $messageHandlerBuilder->build($channelResolver, $referenceSearchService);
    }

    public function buildPollableConsumer(string $endpointId, ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): Closure
    {
        $messageHandlerBuilders = $this->configuration->getMessageHandlerBuilders();
        $messageChannelBuilders = $this->configuration->getChannelBuilders();
        $messageConsumerFactories = $this->configuration->getConsumerFactories();
        foreach ($messageHandlerBuilders as $messageHandlerBuilder) {
            if ($messageHandlerBuilder->getEndpointId() !== $endpointId) {
                continue;
            }
            Assert::keyExists($messageChannelBuilders, $messageHandlerBuilder->getInputMessageChannelName(), "Missing channel with name {$messageHandlerBuilder->getInputMessageChannelName()} for {$messageHandlerBuilder}");
            $messageChannel = $messageChannelBuilders[$messageHandlerBuilder->getInputMessageChannelName()];
            foreach ($messageConsumerFactories as $messageHandlerConsumerBuilder) {
                if ($messageHandlerConsumerBuilder->isSupporting($messageHandlerBuilder, $messageChannel)) {
                    if ($messageHandlerConsumerBuilder->isPollingConsumer()) {
                        return static function ($pollingMetadata) use ($channelResolver, $referenceSearchService, $messageHandlerBuilder, $messageHandlerConsumerBuilder) {
                            static $consumerLifecycle = null;
                            if ($consumerLifecycle) {
                                return $consumerLifecycle;
                            } else {
                                $consumerLifecycle = $messageHandlerConsumerBuilder->build(
                                    $channelResolver,
                                    $referenceSearchService,
                                    $messageHandlerBuilder,
                                    $pollingMetadata
                                );
                                return $consumerLifecycle;
                            }
                        };
                    }
                }
            }
        }

        $channelAdapterConsumerBuilders = $this->configuration->getChannelAdaptersBuilders();
        foreach ($channelAdapterConsumerBuilders as $channelAdapterBuilder) {
            if ($channelAdapterBuilder->getEndpointId() !== $endpointId) {
                continue;
            }
            return static function ($pollingMetadata) use ($referenceSearchService, $channelResolver, $channelAdapterBuilder) {
                static $channelAdapter = null;
                if ($channelAdapter) {
                    return $channelAdapter;
                } else {
                    $channelAdapter = $channelAdapterBuilder->build(
                        $channelResolver,
                        $referenceSearchService,
                        $pollingMetadata
                    );
                    return $channelAdapter;
                }
            };
        }

        throw new \InvalidArgumentException("No polling consumer or inbound adapter found for endpoint {$endpointId}");
    }

    private function getPollingMetadata(string $endpointId): PollingMetadata
    {
        return $this->configuration->getPollingMetadata()[$endpointId] ?? PollingMetadata::create($endpointId);
    }
}