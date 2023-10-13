<?php

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\MessageChannel;

interface PollingConsumerContext
{
    public function get(): ?PollingMetadata;
    public function getPollingConsumerConnectionChannel(): MessageChannel;
    public function getPollingConsumerInterceptors(): array;

}