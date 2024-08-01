<?php

namespace Ecotone\Messaging\Config\Container;

class MethodInterceptionConfiguration
{
    public function __construct(
        private InterfaceToCallReference $interceptedInterfaceToCallReference,
        private array $endpointAnnotations = [],
        private array $requiredInterceptorNames = [],
    )
    {
    }

    public function getInterceptedInterface(): InterfaceToCallReference
    {
        return $this->interceptedInterfaceToCallReference;
    }

    public function getEndpointAnnotations(): array
    {
        return $this->endpointAnnotations;
    }

    public function getRequiredInterceptorNames(): array
    {
        return $this->requiredInterceptorNames;
    }
}