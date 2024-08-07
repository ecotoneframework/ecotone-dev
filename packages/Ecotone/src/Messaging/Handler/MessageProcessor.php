<?php

namespace Ecotone\Messaging\Handler;

use Ecotone\Messaging\Message;

interface MessageProcessor
{
    public function process(Message $message): ?Message;
}
