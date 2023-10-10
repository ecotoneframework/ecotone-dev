<?php

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\InboundChannelAdapter\InboundChannelAdapter;
use Ecotone\Messaging\Endpoint\InterceptedConsumer;
use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Scheduling\Clock;
use Ecotone\Messaging\Scheduling\CronTrigger;
use Ecotone\Messaging\Scheduling\PeriodicTrigger;
use Ecotone\Messaging\Scheduling\SyncTaskScheduler;

class PollingConsumerRunner implements ConsumerLifecycle
{
    public function __construct(
        private NonProxyGateway $gateway,
        private PollingConsumerContext $pollingConsumerContext,
        private Clock $clock,
        private PollableChannel $pollableChannel,
        private string $inputMessageChannelName,
    )
    {
    }

    public function run(): void
    {
        $pollingMetadata = $this->pollingConsumerContext->getPollingMetadata();

        $consumer = new InboundChannelAdapter(
            SyncTaskScheduler::createWithEmptyTriggerContext($this->clock, $pollingMetadata),
            $pollingMetadata->getCron()
                ? CronTrigger::createWith($pollingMetadata->getCron())
                : PeriodicTrigger::create($pollingMetadata->getFixedRateInMilliseconds(), $pollingMetadata->getInitialDelayInMilliseconds()),
            new PollerTaskExecutor($this->inputMessageChannelName, $this->pollableChannel, $this->gateway)
        );

        $interceptors = $this->pollingConsumerContext->getPollingConsumerInterceptors();
        if ($interceptors) {
            $consumer = new InterceptedConsumer($consumer, $interceptors);
        }
        $consumer->run();
    }

    public function stop(): void
    {
    }

    public function isRunningInSeparateThread(): bool
    {
        return true;
    }
}