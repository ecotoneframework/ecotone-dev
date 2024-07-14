<?php

namespace Ecotone\Modelling;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use ReflectionClass;

/**
 * licence Apache-2.0
 */
final class EventSourcingHandlerExecutor implements DefinedObject
{
    /**
     * @param InterfaceToCall[] $eventSourcingHandlerMethods
     */
    public function __construct(private string $aggregateClassName, private array $eventSourcingHandlerMethods)
    {
    }

    public function fill(array $events, ?object $existingAggregate): object
    {
        $aggregate = $existingAggregate ?? (new $this->aggregateClassName());
        foreach ($events as $event) {
            if ($event instanceof Event) {
                $event = $event->getPayload();
            }
            if ($event instanceof SnapshotEvent) {
                $aggregate = $event->getAggregate();

                continue;
            }

            $eventType = TypeDescriptor::createFromVariable($event);
            foreach ($this->eventSourcingHandlerMethods as $methodInterface) {
                if ($methodInterface->getFirstParameter()->canBePassedIn($eventType)) {
                    $aggregate->{$methodInterface->getMethodName()}($event);
                }
            }
        }

        return $aggregate;
    }

    public static function createFor(ClassDefinition $classDefinition, bool $isEventSourced, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        if (! $isEventSourced) {
            return new static($classDefinition->getClassType()->toString(), []);
        }

        $class = new ReflectionClass($classDefinition->getClassType()->toString());

        if ($class->hasMethod('__construct')) {
            $constructMethod = $class->getMethod('__construct');

            if ($constructMethod->getParameters()) {
                throw InvalidArgumentException::create("Constructor for Event Sourced {$classDefinition} should not have any parameters");
            }
            if (! $constructMethod->isPublic()) {
                throw InvalidArgumentException::create("Constructor for Event Sourced {$classDefinition} should be public");
            }
        }

        $aggregateFactoryAnnotation = TypeDescriptor::create(EventSourcingHandler::class);
        $eventSourcingHandlerMethods = [];
        foreach ($classDefinition->getPublicMethodNames() as $method) {
            $methodToCheck = $interfaceToCallRegistry->getFor($classDefinition->getClassType()->toString(), $method);

            if ($methodToCheck->hasMethodAnnotation($aggregateFactoryAnnotation)) {
                if ($methodToCheck->isStaticallyCalled()) {
                    throw InvalidArgumentException::create("{$methodToCheck} is Event Sourcing Handler and should not be static.");
                }
                if ($methodToCheck->getInterfaceParameterAmount() !== 1) {
                    throw InvalidArgumentException::create("{$methodToCheck} is Event Sourcing Handler and should not be have only one parameter type hinted for handled event.");
                }
                if (! $methodToCheck->hasReturnTypeVoid()) {
                    throw InvalidArgumentException::create("{$methodToCheck} is Event Sourcing Handler and should return void return type");
                }

                $eventSourcingHandlerMethods[$method] = $methodToCheck;
            }
        }

        if (! $eventSourcingHandlerMethods) {
            throw InvalidArgumentException::create("Your aggregate {$classDefinition->getClassType()}, is event sourced. You must define atleast one EventSourcingHandler to provide aggregate's identifier after first event.");
        }

        return new static($classDefinition->getClassType()->toString(), $eventSourcingHandlerMethods);
    }

    public function getDefinition(): Definition
    {
        $interfaceToCallReferences = [];
        foreach ($this->eventSourcingHandlerMethods as $methodInterface) {
            $interfaceToCallReferences[] = InterfaceToCallReference::fromInstance($methodInterface);
        }
        return new Definition(self::class, [
            $this->aggregateClassName,
            $interfaceToCallReferences,
        ]);
    }
}
