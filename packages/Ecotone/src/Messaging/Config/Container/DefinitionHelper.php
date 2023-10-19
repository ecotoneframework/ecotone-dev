<?php

namespace Ecotone\Messaging\Config\Container;

use function get_class;
use function serialize;

/**
 * This is a helper class to build a definition from an instance.
 * It should not be used in production code, as it defeats opcache optimizations.
 * It is used in ecotone during the transition to fully compilable components
 *
 * @internal
 */
class DefinitionHelper
{
    public static function buildDefinitionFromInstance(object $object): Definition
    {
        return new Definition(get_class($object), [serialize($object)], [self::class, 'unserializeSerializedObject']);
    }

    public static function unserializeSerializedObject(string $serializedObject): object
    {
        return unserialize($serializedObject);
    }
}
