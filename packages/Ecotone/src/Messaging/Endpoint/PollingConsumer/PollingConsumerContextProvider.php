<?php

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\EndpointRunner;
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

class PollingConsumerContextProvider implements PollingConsumerContext, EndpointRunner, ConsumerLifecycle
{
    private ?PollingMetadata $currentPollingMetadata = null;
    /**
     * @var \Ecotone\Messaging\Endpoint\ConsumerInterceptor[]
     */
    private ?array $consumerInterceptors = null;
    private ?ConsumerLifecycle $runningConsumer = null;

    public function __construct(private Clock $clock, private LoggerInterface $logger, private ContainerInterface $container)
    {
    }

    public function get(): ?PollingMetadata
    {
        return $this->currentPollingMetadata;
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

    /**
     * @inheritDoc
     */
    public function runEndpointWithExecutionPollingMetadata(string $endpointId, ?ExecutionPollingMetadata $executionPollingMetadata): void
    {
        $this->currentPollingMetadata = $this->getPollingMetadataForEndpoint($endpointId)->applyExecutionPollingMetadata($executionPollingMetadata);
        $this->runningConsumer = $this->getPollingConsumer();
        try {
            $this->run();
        } finally {
            $this->currentPollingMetadata = null;
            $this->consumerInterceptors = null;
            $this->runningConsumer = null;
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

    public function run(): void
    {
        $this->runningConsumer->run();
    }

    public function stop(): void
    {
        $this->runningConsumer->stop();
    }

    public function isRunningInSeparateThread(): bool
    {
        $this->runningConsumer->isRunningInSeparateThread();
    }
}