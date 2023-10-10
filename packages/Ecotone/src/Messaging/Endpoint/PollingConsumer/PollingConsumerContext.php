<?php

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Endpoint\InterceptedConsumer;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\MessageHandler;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class PollingConsumerContext
{
    private ?PollingMetadata $pollingMetadata = null;
    /**
     * @var \Ecotone\Messaging\Endpoint\ConsumerInterceptor[]
     */
    private ?array $consumerInterceptors = null;

    public function __construct(private LoggerInterface $logger, private ContainerInterface $container)
    {
    }

    public function getPollingMetadata(): ?PollingMetadata
    {
        return $this->pollingMetadata;
    }

    public function setPollingMetadate(PollingMetadata $executionPollingMetadata)
    {
        $this->reset();
        $this->pollingMetadata = $executionPollingMetadata;
    }

    public function reset(): void
    {
        $this->pollingMetadata = null;
        $this->consumerInterceptors = null;
    }

    public function getPollingConsumerInterceptors(): array
    {
        if (! $this->consumerInterceptors) {
            $this->consumerInterceptors = InterceptedConsumer::createInterceptorsForPollingMetadata($this->pollingMetadata, $this->logger);
        }
        return $this->consumerInterceptors;
    }

    public function getPollingConsumerHandler(): MessageHandler
    {
        $endpointId = $this->pollingMetadata->getEndpointId();
        return $this->container->get("polling.".$endpointId.'.handler');
    }

}