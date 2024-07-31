<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Support\Assert;

abstract class InterceptedMessageProcessorBuilder implements CompilableBuilder
{
    /**
     * @var array<AroundInterceptorBuilder> $orderedAroundInterceptors
     */
    protected array $orderedAroundInterceptors = [];

    /**
     * @var AttributeDefinition[]
     */
    protected array $endpointAnnotations = [];

    public function addAroundMethodInterceptor(AroundInterceptorBuilder ...$methodInterceptors): static
    {
        $this->orderedAroundInterceptors = array_merge($this->orderedAroundInterceptors, $methodInterceptors);

        return $this;
    }
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
}