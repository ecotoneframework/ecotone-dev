<?php

namespace Ecotone\Messaging\Endpoint;

use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\Scheduling\TaskExecutor;

interface GatewayTaskExecutorFactory
{
    public function createTaskExecutor(NonProxyGateway $gateway) : TaskExecutor;
}