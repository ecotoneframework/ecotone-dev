<?php

namespace Ecotone\Messaging\Endpoint;

use Ecotone\Messaging\Endpoint\InboundChannelAdapter\InboundChannelAdapter;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollToGatewayTaskExecutor;
use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\MessagePoller;
use Ecotone\Messaging\Scheduling\Clock;
use Ecotone\Messaging\Scheduling\CronTrigger;
use Ecotone\Messaging\Scheduling\PeriodicTrigger;
use Ecotone\Messaging\Scheduling\SyncTaskScheduler;
use Psr\Log\LoggerInterface;

class InterceptedConsumerRunner implements EndpointRunner
{
    public function __construct(
        private NonProxyGateway $gateway,
        private MessagePoller $messagePoller,
        private PollingMetadata $defaultPollingMetadata,
        private Clock $clock,
        private LoggerInterface $logger)
    {
    }

    public function runEndpointWithExecutionPollingMetadata(string $endpointId, ?ExecutionPollingMetadata $executionPollingMetadata): void
    {
        $this->createConsumer($executionPollingMetadata)->run();
    }

    public function createConsumer(?ExecutionPollingMetadata $executionPollingMetadata): ConsumerLifecycle
    {
        $pollingMetadata = $this->defaultPollingMetadata->applyExecutionPollingMetadata($executionPollingMetadata);
        $interceptors = InterceptedConsumer::createInterceptorsForPollingMetadata($pollingMetadata, $this->logger);
        $interceptedGateway = new InterceptedGateway($this->gateway, $interceptors);

        $executor = new PollToGatewayTaskExecutor($this->messagePoller, $interceptedGateway);

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
}