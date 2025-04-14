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
            if (DbalTypeCompatibility::isDbal4()) {
                // In DBAL 4.x, we need to convert integers to ParameterType enums
                if (is_int($type)) {
                    return match ($type) {
                        ParameterType::NULL->value => ParameterType::NULL,
                        ParameterType::INTEGER->value => ParameterType::INTEGER,
                        ParameterType::STRING->value => ParameterType::STRING,
                        ParameterType::BINARY->value => ParameterType::BINARY,
                        ParameterType::LARGE_OBJECT->value => ParameterType::LARGE_OBJECT,
                        ParameterType::BOOLEAN->value => ParameterType::BOOLEAN,
                        default => ParameterType::STRING,
                    };
                }

                return $type;
            }

            // In DBAL 3.x, we need to convert ParameterType enums to integers
            if ($type instanceof ParameterType) {
                return match ($type) {
                    ParameterType::NULL => ParameterType::NULL->value,
                    ParameterType::INTEGER => ParameterType::INTEGER->value,
                    ParameterType::STRING => ParameterType::STRING->value,
                    ParameterType::BINARY => ParameterType::BINARY->value,
                    ParameterType::LARGE_OBJECT => ParameterType::LARGE_OBJECT->value,
                    ParameterType::BOOLEAN => ParameterType::BOOLEAN->value,
                    default => ParameterType::STRING->value,
                };
            }

            return $type;
        } catch (\Error $e) {
            // If there's an error, return a default value
            return DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : ParameterType::STRING->value;
        }
    }
}
