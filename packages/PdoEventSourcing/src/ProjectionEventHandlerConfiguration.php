<?php

namespace Ecotone\EventSourcing;

class ProjectionEventHandlerConfiguration
{
    public function __construct(private string $className, private string $methodName, private string $eventBusRoutingKey, private string $eventHandlerSynchronousInputChannel)
    {
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getMethodName(): string
    {
        return $this->methodName;
    }

    public function getEventHandlerSynchronousInputChannel(): string
    {
        return $this->eventHandlerSynchronousInputChannel;
    }

    public function getEventBusRoutingKey(): string
    {
        return $this->eventBusRoutingKey;
    }
}
