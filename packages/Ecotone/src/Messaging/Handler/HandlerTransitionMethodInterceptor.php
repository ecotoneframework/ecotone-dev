<?php

namespace Ecotone\Messaging\Handler;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;

interface HandlerTransitionMethodInterceptor
{
    public function getObjectToInvokeOn(): Reference|Definition|DefinedObject;
}