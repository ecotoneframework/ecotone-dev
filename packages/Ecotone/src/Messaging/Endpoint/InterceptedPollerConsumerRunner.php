<?php

namespace Ecotone\Messaging\Endpoint;

use Ecotone\Messaging\Endpoint\InboundChannelAdapter\InboundChannelAdapter;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollerTaskExecutor;
use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Scheduling\Clock;
use Ecotone\Messaging\Scheduling\CronTrigger;
use Ecotone\Messaging\Scheduling\PeriodicTrigger;
use Ecotone\Messaging\Scheduling\SyncTaskScheduler;
use Psr\Log\LoggerInterface;

class InterceptedPollerConsumerRunner implements EndpointRunner
{
    public function __construct(
        private NonProxyGateway $gateway,
        private string $pollableChannelName,
        private PollableChannel $pollableChannel,
        private PollingMetadata $defaultPollingMetadata,
        private Clock $clock,
        private LoggerInterface $logger)
    {
    }

    public function createConsumer(?ExecutionPollingMetadata $executionPollingMetadata): ConsumerLifecycle
    {
        $pollingMetadata = $this->defaultPollingMetadata->applyExecutionPollingMetadata($executionPollingMetadata);
        $interceptors = InterceptedConsumer::createInterceptorsForPollingMetadata($pollingMetadata, $this->logger);
        $interceptedGateway = new InterceptedGateway($this->gateway, $interceptors);
        // We have the choice between PollerTaskExecutor (channel) or InboundChannelTaskExecutor (method call)
        $executor = new PollerTaskExecutor(
            $this->pollableChannelName,
            $this->pollableChannel,
            $interceptedGateway
        );
        $interceptedConsumer = new InboundChannelAdapter(
            SyncTaskScheduler::createWithEmptyTriggerContext($this->clock, $pollingMetadata),
            $pollingMetadata->getCron()
                ? CronTrigger::createWith($pollingMetadata->getCron())
                : PeriodicTrigger::create($pollingMetadata->getFixedRateInMilliseconds(), $pollingMetadata->getInitialDelayInMilliseconds()),
            $executor,
        );

        if ($interceptors) {
            return new InterceptedConsumer($interceptedConsumer, $interceptors);
        } else {
            return $interceptedConsumer;
        }
    }

    public function runEndpointWithExecutionPollingMetadata(string $endpointId, ?ExecutionPollingMetadata $executionPollingMetadata): void
    {
        $this->createConsumer($executionPollingMetadata)->run();
    }
}