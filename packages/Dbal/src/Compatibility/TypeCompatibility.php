<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Compatibility;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;

/**
 * @package Ecotone\Dbal\Compatibility
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * Compatibility layer for DBAL 3.x and 4.x type methods
 */
/**
 * licence Apache-2.0
 */
final class TypeCompatibility
{
    /**
     * Compatibility method for getBindingType
     * In DBAL 3.x, this method returns an integer
     * In DBAL 4.x, this method returns a ParameterType enum
     */
    public static function getBindingType(Type $type): int|ParameterType
    {
        try {
            // Get the binding type, handling potential errors
            try {
                $bindingType = $type->getBindingType();
            } catch (\Throwable $e) {
                // If getBindingType fails, use a default based on the type name
                return self::getDefaultBindingTypeForTypeName($type->getName());
            }

            if (DbalTypeCompatibility::isDbal4()) {
                // In DBAL 4.x, the method returns a ParameterType enum
                if ($bindingType instanceof ParameterType) {
                    return $bindingType;
                }

                // If it's an integer (unexpected in DBAL 4.x), convert it to a ParameterType enum
                if (is_int($bindingType)) {
                    return StatementCompatibility::convertBindingType($bindingType);
                }

                // Default to STRING for DBAL 4.x
                return ParameterType::STRING;
            }

            // In DBAL 3.x, the method returns an integer
            // If we're running in DBAL 4 but the code expects DBAL 3 behavior,
            // convert the ParameterType enum to an integer
            if ($bindingType instanceof ParameterType) {
                return StatementCompatibility::convertBindingType($bindingType);
            }

            // If it's already an integer, return it
            if (is_int($bindingType)) {
                return $bindingType;
            }

            // Default to STRING (2) for DBAL 3.x
            return 2; // ParameterType::STRING->value
        } catch (\Throwable $e) {
            // If there's an error, return a default value
            return DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2; // 2 is STRING in DBAL 3.x
        }
    }

    /**
     * Get a default binding type based on the type name
     */
    private static function getDefaultBindingTypeForTypeName(string $typeName): int|ParameterType
    {
        // Map common type names to binding types
        $map = [
            'string' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'text' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'ascii_string' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'integer' => DbalTypeCompatibility::isDbal4() ? ParameterType::INTEGER : 1,
            'smallint' => DbalTypeCompatibility::isDbal4() ? ParameterType::INTEGER : 1,
            'bigint' => DbalTypeCompatibility::isDbal4() ? ParameterType::INTEGER : 1,
            'boolean' => DbalTypeCompatibility::isDbal4() ? ParameterType::BOOLEAN : 5,
            'decimal' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'float' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'binary' => DbalTypeCompatibility::isDbal4() ? ParameterType::BINARY : 3,
            'blob' => DbalTypeCompatibility::isDbal4() ? ParameterType::BINARY : 3,
            'guid' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'json' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'array' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'simple_array' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'object' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'date' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'date_immutable' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'datetime' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'datetime_immutable' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'datetimetz' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'datetimetz_immutable' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'time' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
            'time_immutable' => DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2,
        ];

        return $map[strtolower($typeName)] ?? (DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2);
    }
}
