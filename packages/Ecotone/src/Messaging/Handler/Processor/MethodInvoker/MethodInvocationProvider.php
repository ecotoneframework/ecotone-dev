<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Message;

/**
 * @licence Apache-2.0
 */
interface MethodInvocationProvider
{
    public function getMethodInvocation(Message $message): MethodInvocation;
}
