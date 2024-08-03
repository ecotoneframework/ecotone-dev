<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\MethodInterceptorsConfiguration;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Support\Assert;

abstract class InterceptedMessageProcessorBuilder implements CompilableBuilder
{
    abstract function getInterceptedInterface(): InterfaceToCallReference;

    abstract function compile(MessagingContainerBuilder $builder, ?MethodInterceptorsConfiguration $interceptorsConfiguration = null): Definition|Reference;
}