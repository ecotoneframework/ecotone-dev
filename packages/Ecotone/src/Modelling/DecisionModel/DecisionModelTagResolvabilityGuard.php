<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Type\UnionType;
use ReflectionClass;

use function class_exists;
use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionModelTagResolvabilityGuard
{
    /**
     * @param array<class-string, array{tagNames: string[], handledEventClasses: class-string[]}> $rawDefinitions
     */
    public static function assertEveryModelTagResolvableFromItsMessage(
        AnnotationFinder $annotationFinder,
        InterfaceToCallRegistry $interfaceToCallRegistry,
        array $rawDefinitions,
    ): void {
        foreach ([CommandHandler::class, EventHandler::class, QueryHandler::class] as $handlerAnnotationClass) {
            foreach ($annotationFinder->findAnnotatedMethods($handlerAnnotationClass) as $annotatedMethod) {
                $interfaceToCall = $interfaceToCallRegistry->getFor($annotatedMethod->getClassName(), $annotatedMethod->getMethodName());
                $messageClass = self::concreteMessageClassOf($interfaceToCall);

                if ($messageClass === null) {
                    continue;
                }

                foreach ($interfaceToCall->getInterfaceParameters() as $parameter) {
                    $modelClass = self::modelClassResolvedByConvention($parameter);

                    if ($modelClass === null || ! isset($rawDefinitions[$modelClass])) {
                        continue;
                    }

                    foreach ($rawDefinitions[$modelClass]['tagNames'] as $tagName) {
                        if (! MessageTagValueResolver::canResolve($tagName, $messageClass)) {
                            throw ConfigurationException::create(sprintf(
                                "%s injects DecisionModel %s, but its message %s has no property for tag '%s'. Add #[EventTag('%s')] to the property carrying the value, name it '%s', '%sId' or '%s_id', or map it explicitly with #[Fetch].",
                                $interfaceToCall,
                                $modelClass,
                                $messageClass,
                                $tagName,
                                $tagName,
                                $tagName,
                                $tagName,
                                $tagName,
                            ));
                        }
                    }
                }
            }
        }
    }

    private static function concreteMessageClassOf(InterfaceToCall $interfaceToCall): ?string
    {
        if ($interfaceToCall->getInterfaceParameterAmount() === 0 || ! $interfaceToCall->getFirstParameter()->isClassOrInterface()) {
            return null;
        }

        $messageClass = $interfaceToCall->getFirstParameter()->getTypeHint();

        if (! class_exists($messageClass) || (new ReflectionClass($messageClass))->isAbstract() || DecisionModelReflection::isDecisionModel($messageClass)) {
            return null;
        }

        return $messageClass;
    }

    private static function modelClassResolvedByConvention(InterfaceParameter $parameter): ?string
    {
        if ($parameter->hasAnnotation(Fetch::class)) {
            return null;
        }

        $type = $parameter->getTypeDescriptor();
        if ($type instanceof UnionType) {
            $type = $type->withoutNull();
        }

        $typeHint = $type->toString();

        return DecisionModelReflection::isDecisionModel($typeHint) ? $typeHint : null;
    }
}
