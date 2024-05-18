<?php

namespace Ecotone\Modelling;

use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\EnterpriseModeDecider;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use ReflectionClass;

final class EventSourcingHandlerExecutor
{
    /**
     * @param EventSourcingHandlerMethod[] $eventSourcingHandlerMethods
     */
    public function __construct(
        private string $aggregateClassName,
        private array $eventSourcingHandlerMethods,
        private EnterpriseModeDecider $enterpriseModeDecider,
    )
    {
    }

    /**
     * @param Event[] $events
     */
    public function fill(array $events, ?object $existingAggregate): object
    {
        $aggregate = $existingAggregate ?? (new $this->aggregateClassName());
        foreach ($events as $event) {
            if ($event instanceof SnapshotEvent) {
                $aggregate = $event->getAggregate();

                continue;
            }
            $eventPayload = null;
            $metadata = [];
            if ($event instanceof Event) {
                $eventPayload = $event->getPayload();
                $eventType = TypeDescriptor::createFromVariable($eventPayload);
                $metadata  = $event->getMetadata();
            }else {
                $eventType = TypeDescriptor::createFromVariable($event);
                $eventPayload = $event;
            }

            $message = MessageBuilder::withPayload($eventPayload)
                ->setMultipleHeaders($metadata)
                ->build();
            foreach ($this->eventSourcingHandlerMethods as $eventSourcingHandler) {
                $eventSourcingHandlerInterface = $eventSourcingHandler->getInterfaceToCall();
                if ($eventSourcingHandlerInterface->getFirstParameter()->canBePassedIn($eventType)) {
                    (new MethodInvoker(
                        $aggregate,
                        $eventSourcingHandlerInterface->getMethodName(),
                        $eventSourcingHandler->getParameterConverters(),
                        $eventSourcingHandler->getInterfaceToCall()
                    ))->executeEndpoint($message);
                }
            }
        }

        return $aggregate;
    }
}
