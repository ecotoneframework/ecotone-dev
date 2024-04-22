<?php

namespace Ecotone\Modelling;

use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use ReflectionClass;

final class EventSourcingHandlerExecutor implements DefinedObject
{
    /**
     * @param EventSourcingHandlerMethod[] $eventSourcingHandlerMethods
     */
    public function __construct(private string $aggregateClassName, private array $eventSourcingHandlerMethods)
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

            $eventType = TypeDescriptor::createFromVariable($event->getPayload());
            $message = MessageBuilder::withPayload($event->getPayload())
                ->setMultipleHeaders($event->getMetadata())
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

    public static function createFor(ClassDefinition $classDefinition, bool $isEventSourced, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        if (! $isEventSourced) {
            return new static($classDefinition->getClassType()->toString(), []);
        }

        $parameterConverterFactory = ParameterConverterAnnotationFactory::create();
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
                if ($methodToCheck->getInterfaceParameterAmount() < 1) {
                    throw InvalidArgumentException::create("{$methodToCheck} is Event Sourcing Handler and should have at least one parameter.");
                }
                if (!$methodToCheck->getFirstParameter()->isObjectTypeHint()) {
                    throw InvalidArgumentException::create("{$methodToCheck} is Event Sourcing Handler and should have first parameter as Event Class type hint.");
                }
                if (! $methodToCheck->hasReturnTypeVoid()) {
                    throw InvalidArgumentException::create("{$methodToCheck} is Event Sourcing Handler and should return void return type");
                }

                $eventSourcingHandlerMethods[$method] = new EventSourcingHandlerMethodBuilder(
                    $methodToCheck,
                    $parameterConverterFactory->createParameterWithDefaults($methodToCheck)
                );
            }
        }

        if (! $eventSourcingHandlerMethods) {
            throw InvalidArgumentException::create("Your aggregate {$classDefinition->getClassType()}, is event sourced. You must define at least one EventSourcingHandler to provide aggregate's identifier after first event.");
        }

        return new static($classDefinition->getClassType()->toString(), $eventSourcingHandlerMethods);
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [
            $this->aggregateClassName,
            $this->eventSourcingHandlerMethods,
        ]);
    }
}
