<?php

namespace Ecotone\Messaging\Config;

use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Conversion\AutoCollectionConversionService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\ConverterBuilder;
use Ecotone\Messaging\Endpoint\ChannelAdapterConsumerBuilder;
use Ecotone\Messaging\Endpoint\MessageHandlerConsumerBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;

class PreparedConfiguration
{
    /**
     * @param InterfaceToCall[] $interfacesToCall
     * @param ConverterBuilder[] $converterBuilders
     * @param MessageChannelBuilder[] $channelBuilders
     * @param array<string, ChannelInterceptorBuilder[]> $channelInterceptorsBuilders
     * @param GatewayProxyBuilder[][] $gatewayBuilders
     * @param MessageHandlerConsumerBuilder[] $consumerFactories
     * @param PollingMetadata[] $pollingMetadata
     * @param MessageHandlerBuilder[] $messageHandlerBuilders
     * @param ChannelAdapterConsumerBuilder[] $channelAdaptersBuilders
     * @param ConsoleCommandConfiguration[] $consoleCommands
     * @param string[] $gatewayClassesToGenerateProxies
     */
    public function __construct(
        private InterfaceToCallRegistry $interfaceToCallRegistry,
        private ServiceConfiguration $serviceConfiguration,
        private array $converterBuilders,
        private array $channelBuilders,
        private array $channelInterceptorsBuilders,
        private array $gatewayBuilders,
        private array $consumerFactories,
        private array $pollingMetadata,
        private array $messageHandlerBuilders,
        private array $channelAdaptersBuilders,
        private array $consoleCommands,
        private array $referencesToRegister,
        private array $gatewayClassesToGenerateProxies,
    ) {
    }

    /**
     * @return MessageChannelBuilder[]
     */
    public function getChannelBuilders(): array
    {
        return $this->channelBuilders;
    }

    /**
     * @return array<string, ChannelInterceptorBuilder[]>
     */
    public function getChannelInterceptorsBuilders(): array
    {
        return $this->channelInterceptorsBuilders;
    }

    /**
     * @return GatewayProxyBuilder[][]
     */
    public function getGatewayBuilders(): array
    {
        return $this->gatewayBuilders;
    }

    /**
     * @return MessageHandlerConsumerBuilder[]
     */
    public function getConsumerFactories(): array
    {
        return $this->consumerFactories;
    }

    /**
     * @return PollingMetadata[]
     */
    public function getPollingMetadata(): array
    {
        return $this->pollingMetadata;
    }

    /**
     * @return array
     */
    public function getMessageHandlerBuilders(): array
    {
        return $this->messageHandlerBuilders;
    }

    /**
     * @return array
     */
    public function getChannelAdaptersBuilders(): array
    {
        return $this->channelAdaptersBuilders;
    }

    /**
     * @return array
     */
    public function getConsoleCommands(): array
    {
        return $this->consoleCommands;
    }

    /**
     * @return array
     */
    public function getConverterBuilders(): array
    {
        return $this->converterBuilders;
    }

    /**
     * @return ServiceConfiguration
     */
    public function getServiceConfiguration(): ServiceConfiguration
    {
        return $this->serviceConfiguration;
    }

    public function buildMessagingSystemFromConfiguration(ReferenceSearchService $referenceSearchService): ConfiguredMessagingSystem
    {
        $converters = [];
        foreach ($this->converterBuilders as $converterBuilder) {
            $converters[] = $converterBuilder->build($referenceSearchService);
        }
        $referenceSearchService = $this->prepareReferenceSearchServiceWithInternalReferences($referenceSearchService, $converters, $this->interfaceToCallRegistry);

        return MessagingSystem::createFrom(
            $referenceSearchService,
            $this->channelBuilders,
            $this->channelInterceptorsBuilders,
            $this->gatewayBuilders,
            $this->consumerFactories,
            $this->pollingMetadata,
            $this->messageHandlerBuilders,
            $this->channelAdaptersBuilders,
            $this->consoleCommands
        );
    }

    private function prepareReferenceSearchServiceWithInternalReferences(ReferenceSearchService $referenceSearchService, array $converters, InterfaceToCallRegistry $interfaceToCallRegistry): InMemoryReferenceSearchService
    {
        return InMemoryReferenceSearchService::createWithReferenceService(
            $referenceSearchService,
            array_merge(
                $this->referencesToRegister,
                [
                    ConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith($converters),
                    InterfaceToCallRegistry::REFERENCE_NAME => $interfaceToCallRegistry,
                    ServiceConfiguration::class => $this->serviceConfiguration,
                ]
            )
        );
    }
}