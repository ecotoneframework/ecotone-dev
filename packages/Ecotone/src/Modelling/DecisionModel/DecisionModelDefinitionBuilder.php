<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\EventSourcing\Tagging\EventTagRegistry;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Type;
use ReflectionClass;

use function array_diff;
use function array_intersect;
use function array_unique;
use function array_values;
use function implode;
use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionModelDefinitionBuilder
{
    /**
     * @param string[] $explicitTagNames
     */
    public static function buildFor(
        ClassDefinition $classDefinition,
        InterfaceToCallRegistry $interfaceToCallRegistry,
        EventTagRegistry $eventTagRegistry,
        array $explicitTagNames,
    ): DecisionModelDefinition {
        $className = $classDefinition->getClassType()->toString();

        self::assertPublicNoArgumentConstructor($className);
        self::assertNoMessageHandlerDeclaredOnTheModelItself($classDefinition, $interfaceToCallRegistry);

        $handledEventClasses = self::findHandledEventClasses($classDefinition, $interfaceToCallRegistry);

        if ($handledEventClasses === []) {
            throw ConfigurationException::create(sprintf(
                'DecisionModel %s must define at least one #[EventSourcingHandler].',
                $className
            ));
        }

        $tagNames = $explicitTagNames !== []
            ? array_values(array_unique($explicitTagNames))
            : self::intersectionOfTagNames($handledEventClasses, $eventTagRegistry);

        if ($tagNames === []) {
            throw ConfigurationException::create(sprintf(
                'DecisionModel %s is scoped by no tag name, so it would fold no event and guard nothing: its handled event(s) %s share no #[EventTag] name. Add #[EventTag] to the event(s) so that every handled event carries a common tag name, or scope the model explicitly with #[DecisionModel(tags: [...])].',
                $className,
                self::tagNamesPerHandledEvent($handledEventClasses, $eventTagRegistry),
            ));
        }

        foreach ($handledEventClasses as $handledEventClass) {
            $eventTagNames = $eventTagRegistry->tagNamesFor($handledEventClass);
            $missing = array_diff($tagNames, $eventTagNames);

            if ($missing !== []) {
                throw ConfigurationException::create(sprintf(
                    "DecisionModel %s is scoped by tag(s) '%s', but its handled event %s does not carry %s.",
                    $className,
                    implode(', ', $tagNames),
                    $handledEventClass,
                    implode(', ', $missing)
                ));
            }
        }

        return new DecisionModelDefinition($className, $tagNames, $handledEventClasses);
    }

    private static function assertPublicNoArgumentConstructor(string $className): void
    {
        $reflectionClass = new ReflectionClass($className);

        if ($reflectionClass->hasMethod('__construct')) {
            $constructor = $reflectionClass->getMethod('__construct');

            if ($constructor->getParameters() !== []) {
                throw ConfigurationException::create("Constructor for DecisionModel {$className} should not have any parameters.");
            }

            if (! $constructor->isPublic()) {
                throw ConfigurationException::create("Constructor for DecisionModel {$className} should be public.");
            }
        }
    }

    private static function assertNoMessageHandlerDeclaredOnTheModelItself(ClassDefinition $classDefinition, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $className = $classDefinition->getClassType()->toString();

        foreach ([CommandHandler::class, EventHandler::class, QueryHandler::class] as $handlerAnnotationClass) {
            $annotationType = Type::object($handlerAnnotationClass);

            foreach ($classDefinition->getPublicMethodNames() as $method) {
                $interfaceToCall = $interfaceToCallRegistry->getFor($className, $method);

                if ($interfaceToCall->hasMethodAnnotation($annotationType)) {
                    throw ConfigurationException::create(sprintf(
                        '%s::%s is a %s, but %s is a DecisionModel -- models are injected, they never own handlers.',
                        $className,
                        $method,
                        $handlerAnnotationClass,
                        $className,
                    ));
                }
            }
        }
    }

    /**
     * @return class-string[]
     */
    private static function findHandledEventClasses(ClassDefinition $classDefinition, InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        $className = $classDefinition->getClassType()->toString();
        $eventSourcingHandlerAnnotation = Type::object(EventSourcingHandler::class);

        $handledEventClasses = [];
        foreach ($classDefinition->getPublicMethodNames() as $method) {
            $interfaceToCall = $interfaceToCallRegistry->getFor($className, $method);

            if (! $interfaceToCall->hasMethodAnnotation($eventSourcingHandlerAnnotation)) {
                continue;
            }

            if ($interfaceToCall->isStaticallyCalled()) {
                throw ConfigurationException::create("{$interfaceToCall} is Event Sourcing Handler and should not be static.");
            }

            if ($interfaceToCall->getInterfaceParameterAmount() < 1 || ! $interfaceToCall->getFirstParameter()->isClassOrInterface()) {
                throw ConfigurationException::create("{$interfaceToCall} is Event Sourcing Handler and should have first parameter as Event Class type hint.");
            }

            $firstParameterTypeHint = $interfaceToCall->getFirstParameter()->getTypeHint();
            if (interface_exists($firstParameterTypeHint) && ! class_exists($firstParameterTypeHint)) {
                throw ConfigurationException::create("{$interfaceToCall} is a DecisionModel Event Sourcing Handler and its first parameter must be a concrete Event class, an interface or union type is not allowed, as its tag names could not be derived.");
            }

            $handledEventClasses[] = $firstParameterTypeHint;
        }

        return array_values(array_unique($handledEventClasses));
    }

    /**
     * @param class-string[] $handledEventClasses
     */
    private static function tagNamesPerHandledEvent(array $handledEventClasses, EventTagRegistry $eventTagRegistry): string
    {
        $descriptions = [];
        foreach ($handledEventClasses as $handledEventClass) {
            $eventTagNames = $eventTagRegistry->tagNamesFor($handledEventClass);

            $descriptions[] = $eventTagNames === []
                ? sprintf('%s (no #[EventTag])', $handledEventClass)
                : sprintf('%s (%s)', $handledEventClass, implode(', ', $eventTagNames));
        }

        return implode(', ', $descriptions);
    }

    /**
     * @param class-string[] $handledEventClasses
     * @return string[]
     */
    private static function intersectionOfTagNames(array $handledEventClasses, EventTagRegistry $eventTagRegistry): array
    {
        $tagNames = null;
        foreach ($handledEventClasses as $handledEventClass) {
            $eventTagNames = $eventTagRegistry->tagNamesFor($handledEventClass);
            $tagNames = $tagNames === null ? $eventTagNames : array_values(array_intersect($tagNames, $eventTagNames));
        }

        return $tagNames ?? [];
    }
}
