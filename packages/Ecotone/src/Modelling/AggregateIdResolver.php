<?php
declare(strict_types=1);

namespace Ecotone\Modelling;

use ReflectionClass;
use Ecotone\Messaging\MessagingException;

class AggregateIdResolver
{
    /**
     * @throws NoCorrectIdentifierDefinedException
     * @throws MessagingException
     */
    public static function resolve(string $aggregateClass, $id)
    {
        if (is_object($id)) {
            $reflectionIdObject = new ReflectionClass(get_class($id));

            if($reflectionIdObject->isEnum()) {
                return $id->value;
            }

            if (!$reflectionIdObject->hasMethod("__toString")) {
                throw NoCorrectIdentifierDefinedException::create(sprintf('Aggregate %s has identifier which is class. You must define __toString method for %s', $aggregateClass, get_class($id)));
            }

            return (string) $id;
        }

        if (is_array($id)) {
            throw NoCorrectIdentifierDefinedException::create(sprintf('Aggregate %s has identifier which is array. Array is not allowed as identifier', $aggregateClass));
        }

        return $id;
    }

    /**
     * @throws NoCorrectIdentifierDefinedException
     * @throws MessagingException
     */
    public static function resolveArrayOfIdentifiers(string $aggregateClass, array $ids): array
    {
        $resolvedIdentifiers = [];
        foreach ($ids as $name => $id) {
            $resolvedIdentifiers[$name] = self::resolve($aggregateClass, $id);
        }

        return $resolvedIdentifiers;
    }
}
