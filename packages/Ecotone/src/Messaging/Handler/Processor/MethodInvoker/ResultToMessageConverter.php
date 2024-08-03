<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Message;

interface ResultToMessageConverter
{
    public function convertToMessage(Message $requestMessage, mixed $result): ?Message;
}