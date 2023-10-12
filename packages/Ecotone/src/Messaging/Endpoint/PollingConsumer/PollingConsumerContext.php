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

interface PollingConsumerContext
{
    public function get(): ?PollingMetadata;
    public function getPollingConsumerConnectionChannel(): MessageChannel;
    public function getPollingConsumerInterceptors(): array;

}