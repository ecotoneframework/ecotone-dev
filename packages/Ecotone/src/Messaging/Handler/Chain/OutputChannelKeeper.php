<?php

namespace Ecotone\Messaging\Handler\Chain;

use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
class OutputChannelKeeper
{
    private NonProxyGateway $gateway;

    public function __construct(NonProxyGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function keep(Message $message): ?Message
    {
        return $this->gateway->execute([$message]);
    }
}
