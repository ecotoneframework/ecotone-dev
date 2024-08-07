<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Message;

class PassthroughMessageConverter implements ResultToMessageConverter
{
    public function convertToMessage(Message $requestMessage, mixed $result): ?Message
    {
        return $requestMessage;
    }
}
