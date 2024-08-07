<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Message;

interface MethodCallProvider
{
    public function getMethodInvocation(Message $message): MethodInvocation;
}
