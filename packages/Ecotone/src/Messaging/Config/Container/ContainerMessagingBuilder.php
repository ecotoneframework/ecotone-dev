<?php

namespace Ecotone\Messaging\Config\Container;

interface ContainerMessagingBuilder
{
    public function createDefinition(string $className, array $constructorArguments = []): Definition;
    public function getInterfaceToCallReference(string $className, string $methodName): Reference;
    public function register(Definition $definition): Reference;
}