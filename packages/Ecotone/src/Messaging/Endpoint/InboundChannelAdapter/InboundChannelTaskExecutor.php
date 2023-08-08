<?php

namespace Ecotone\Messaging\Endpoint\InboundChannelAdapter;

use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\Scheduling\TaskExecutor;

/**
 * Class InboundChannelGatewayExecutor
 * @package Ecotone\Messaging\Endpoint\InboundChannelAdapter
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 * @internal
 */
class InboundChannelTaskExecutor implements TaskExecutor
{
    private object $serviceToCall;
    private string $method;
    private NonProxyGateway $inboundChannelGateway;

    public function __construct(NonProxyGateway $inboundChannelGateway, object $serviceToCall, string $method)
    {
        $this->serviceToCall = $serviceToCall;
        $this->method = $method;
        $this->inboundChannelGateway = $inboundChannelGateway;
    }

    public function execute(PollingMetadata $pollingMetadata): void
    {
        $result = $this->serviceToCall->{$this->method}();

        if (! is_null($result)) {
            $this->inboundChannelGateway->execute([$result]);
        }
    }
}
