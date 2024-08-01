<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Support\Assert;

abstract class InterceptedMessageProcessorBuilder implements CompilableBuilder
{
    /**
     * @var AttributeDefinition[]
     */
    protected array $endpointAnnotations = [];

    public function withEndpointAnnotations(array $endpointAnnotations): static
    {
        Assert::allInstanceOfType($endpointAnnotations, AttributeDefinition::class);
        $this->endpointAnnotations = $endpointAnnotations;

        return $this;
    }

    public function getEndpointAnnotations(): array
    {
        return $this->endpointAnnotations;
    }

    abstract function getInterceptedInterface(): InterfaceToCallReference;
}