<?php

namespace Ecotone\Messaging\Endpoint\MessagePoller;

use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;

interface MessagePoller
{
    public function poll(PollingMetadata $pollingMetadata): ?Message;
}