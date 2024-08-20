<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;

/**
 * @licence Apache-2.0
 */
interface InterceptedMessageProcessorBuilder extends CompilableBuilder
{
    public function getInterceptedInterface(): InterfaceToCallReference;
}
