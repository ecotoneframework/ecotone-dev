<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Compatibility;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;

/**
 * @package Ecotone\Dbal\Compatibility
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * Compatibility layer for DBAL 3.x and 4.x statement methods
 */
/**
 * licence Apache-2.0
 */
final class StatementCompatibility
{
    /**
     * Compatibility method for bindValue
     * In DBAL 3.x, the type parameter is an integer
     * In DBAL 4.x, the type parameter is a ParameterType enum
     */
    public static function convertBindingType($type): int|ParameterType
    {
        try {
            // Handle null values
            if ($type === null) {
                return DbalTypeCompatibility::isDbal4() ? ParameterType::NULL : 0; // 0 is NULL in DBAL 3.x
            }

            if (DbalTypeCompatibility::isDbal4()) {
                // In DBAL 4.x, we need to convert integers to ParameterType enums
                if (is_int($type)) {
                    // In DBAL 4.x, ParameterType is an enum with values
                    try {
                        return match ($type) {
                            0 => ParameterType::NULL, // NULL = 0
                            1 => ParameterType::INTEGER, // INTEGER = 1
                            2 => ParameterType::STRING, // STRING = 2
                            3 => ParameterType::BINARY, // BINARY = 3
                            4 => ParameterType::LARGE_OBJECT, // LARGE_OBJECT = 4
                            5 => ParameterType::BOOLEAN, // BOOLEAN = 5
                            default => ParameterType::STRING,
                        };
                    } catch (\Error $e) {
                        // If the enum values don't match, use reflection to get the correct values
                        $reflection = new \ReflectionClass(ParameterType::class);
                        $cases = $reflection->getConstants();

                        foreach ($cases as $name => $value) {
                            if ($value === $type || (is_object($value) && method_exists($value, 'value') && $value->value === $type)) {
                                return $value;
                            }
                        }

                        // Default to STRING if no match is found
                        return ParameterType::STRING;
                    }
                }

                // If it's already a ParameterType enum, return it as is
                if ($type instanceof ParameterType) {
                    return $type;
                }

                // If it's a string representation of a type, convert it
                if (is_string($type)) {
                    return match (strtoupper($type)) {
                        'NULL' => ParameterType::NULL,
                        'INTEGER', 'INT' => ParameterType::INTEGER,
                        'STRING', 'STR' => ParameterType::STRING,
                        'BINARY', 'BLOB' => ParameterType::BINARY,
                        'LARGE_OBJECT', 'LOB' => ParameterType::LARGE_OBJECT,
                        'BOOLEAN', 'BOOL' => ParameterType::BOOLEAN,
                        default => ParameterType::STRING,
                    };
                }

                // Default to STRING for DBAL 4.x
                return ParameterType::STRING;
            } else {
                // In DBAL 3.x, we need to convert ParameterType enums to integers
                if ($type instanceof ParameterType) {
                    try {
                        // Try to access the value property of the enum
                        return match ($type) {
                            ParameterType::NULL => 0, // NULL = 0
                            ParameterType::INTEGER => 1, // INTEGER = 1
                            ParameterType::STRING => 2, // STRING = 2
                            ParameterType::BINARY => 3, // BINARY = 3
                            ParameterType::LARGE_OBJECT => 4, // LARGE_OBJECT = 4
                            ParameterType::BOOLEAN => 5, // BOOLEAN = 5
                            default => 2, // Default to STRING (2)
                        };
                    } catch (\Error $e) {
                        // If the enum values don't match, use reflection to get the correct values
                        $reflection = new \ReflectionClass(ParameterType::class);
                        $cases = $reflection->getConstants();

                        // Map enum names to DBAL 3.x integer values
                        $mapping = [
                            'NULL' => 0,
                            'INTEGER' => 1,
                            'STRING' => 2,
                            'BINARY' => 3,
                            'LARGE_OBJECT' => 4,
                            'BOOLEAN' => 5
                        ];

                        foreach ($cases as $name => $value) {
                            if ($value === $type) {
                                return $mapping[$name] ?? 2; // Default to STRING (2) if not found
                            }
                        }

                        // Default to STRING (2) if no match is found
                        return 2;
                    }
                }

                // If it's already an integer, return it as is
                if (is_int($type)) {
                    return $type;
                }

                // If it's a string representation of a type, convert it
                if (is_string($type)) {
                    return match (strtoupper($type)) {
                        'NULL' => 0,
                        'INTEGER', 'INT' => 1,
                        'STRING', 'STR' => 2,
                        'BINARY', 'BLOB' => 3,
                        'LARGE_OBJECT', 'LOB' => 4,
                        'BOOLEAN', 'BOOL' => 5,
                        default => 2, // Default to STRING (2)
                    };
                }

                // Default to STRING (2) for DBAL 3.x
                return 2;
            }
        } catch (\Throwable $e) {
            // If there's any error, return a default value based on DBAL version
            return DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : 2; // 2 is STRING in DBAL 3.x
        }
    }
}
