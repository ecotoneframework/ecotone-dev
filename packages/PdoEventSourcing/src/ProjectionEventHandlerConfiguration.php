<?php

namespace Ecotone\EventSourcing;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

class ProjectionEventHandlerConfiguration implements DefinedObject
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

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [
            $this->className,
            $this->methodName,
            $this->eventBusRoutingKey,
            $this->eventHandlerSynchronousInputChannel,
        ]);
    }
}
