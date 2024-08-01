<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Support\Assert;

abstract class InterceptedMessageProcessorBuilder implements CompilableBuilder
{

    private bool $interceptionIsEnabled = false;

    public function enableInterception(): static
    {
        $this->interceptionIsEnabled = true;
        return $this;
    }

    abstract function getInterceptedInterface(): InterfaceToCallReference;

    protected function interceptMethodCall(MessagingContainerBuilder $builder, array $endpointAnnotations, Definition $methodCallProviderDefinition): Definition
    {
        if ($this->interceptionIsEnabled) {
            return $builder->interceptMethodCall($this->getInterceptedInterface(), $endpointAnnotations, $methodCallProviderDefinition);
        } else {
            return $methodCallProviderDefinition;
        }
    }
}