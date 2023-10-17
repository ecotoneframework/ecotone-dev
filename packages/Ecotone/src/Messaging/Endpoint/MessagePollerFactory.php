<?php

namespace Ecotone\Messaging\Endpoint;

use Ecotone\Messaging\Endpoint\MessagePoller\MessagePoller;
use Ecotone\Messaging\Handler\NonProxyGateway;

interface MessagePollerFactory
{
    public function createMessagePoller(NonProxyGateway $gateway): MessagePoller;
}