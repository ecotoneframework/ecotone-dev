<?php

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\InboundChannelAdapter\InboundChannelAdapter;
use Ecotone\Messaging\Endpoint\InterceptedConsumer;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Scheduling\Clock;
use Ecotone\Messaging\Scheduling\CronTrigger;
use Ecotone\Messaging\Scheduling\PeriodicTrigger;
use Ecotone\Messaging\Scheduling\SyncTaskScheduler;
use Ecotone\Messaging\Scheduling\TaskExecutor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class PollingConsumerContext
{
    private ?PollingMetadata $currentPollingMetadata = null;
    /**
     * @var \Ecotone\Messaging\Endpoint\ConsumerInterceptor[]
     */
    private ?array $consumerInterceptors = null;

    public function __construct(private Clock $clock, private LoggerInterface $logger, private ContainerInterface $container)
    {
    }

    public function getErrorChannelName(): string
    {
        return $this->currentPollingMetadata->getErrorChannelName();
    }

    public function getPollingConsumerConnectionChannel(): MessageChannel
    {
        $endpointId = $this->currentPollingMetadata->getEndpointId();
        return $this->container->get("polling.".$endpointId.'.channel');
    }

    public function getPollingConsumerInterceptors(): array
    {
        if (! $this->consumerInterceptors) {
            $this->consumerInterceptors = InterceptedConsumer::createInterceptorsForPollingMetadata($this->currentPollingMetadata, $this->logger);
        }
        return $this->consumerInterceptors;
    }

    public function runEndpointWithExecutionPollingMetadata(string $endpointId, ?ExecutionPollingMetadata $executionPollingMetadata)
    {
        $this->currentPollingMetadata = $this->getPollingMetadataForEndpoint($endpointId)->applyExecutionPollingMetadata($executionPollingMetadata);

        try {
            $this->getPollingConsumer()->run();
        } finally {
            $this->currentPollingMetadata = null;
            $this->consumerInterceptors = null;
        }
    }

    private function getPollingConsumer(): ConsumerLifecycle
    {
        $consumer = new InboundChannelAdapter(
            SyncTaskScheduler::createWithEmptyTriggerContext($this->clock, $this->currentPollingMetadata),
            $this->currentPollingMetadata->getCron()
                ? CronTrigger::createWith($this->currentPollingMetadata->getCron())
                : PeriodicTrigger::create($this->currentPollingMetadata->getFixedRateInMilliseconds(), $this->currentPollingMetadata->getInitialDelayInMilliseconds()),
            $this->getPollingConsumerExecutor(),
        );

        $interceptors = $this->getPollingConsumerInterceptors();
        if ($interceptors) {
            $consumer = new InterceptedConsumer($consumer, $interceptors);
        }

        return $consumer;
    }

    private function getPollingConsumerExecutor(): TaskExecutor
    {
        $endpointId = $this->currentPollingMetadata->getEndpointId();
        return $this->container->get("polling.".$endpointId.'.executor');
    }

    private function getPollingMetadataForEndpoint(string $endpointId): PollingMetadata
    {
        return $this->container->get("polling.".$endpointId.'.metadata');
    }

}