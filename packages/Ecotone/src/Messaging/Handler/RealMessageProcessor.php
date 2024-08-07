<?php

namespace Ecotone\Messaging\Handler;

use Ecotone\Messaging\Message;

interface RealMessageProcessor
{
    public function process(Message $message): ?Message;
}
