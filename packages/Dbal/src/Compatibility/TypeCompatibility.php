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
            $bindingType = $type->getBindingType();

            if (DbalTypeCompatibility::isDbal4()) {
                // In DBAL 4.x, the method returns a ParameterType enum
                return $bindingType;
            }

            // In DBAL 3.x, the method returns an integer
            // If we're running in DBAL 4 but the code expects DBAL 3 behavior,
            // convert the ParameterType enum to an integer
            if ($bindingType instanceof ParameterType) {
                return match ($bindingType) {
                    ParameterType::NULL => ParameterType::NULL->value,
                    ParameterType::INTEGER => ParameterType::INTEGER->value,
                    ParameterType::STRING => ParameterType::STRING->value,
                    ParameterType::BINARY => ParameterType::BINARY->value,
                    ParameterType::LARGE_OBJECT => ParameterType::LARGE_OBJECT->value,
                    ParameterType::BOOLEAN => ParameterType::BOOLEAN->value,
                    default => ParameterType::STRING->value,
                };
            }

            return $bindingType;
        } catch (\Error $e) {
            // If there's an error, return a default value
            return DbalTypeCompatibility::isDbal4() ? ParameterType::STRING : ParameterType::STRING->value;
        }
    }
}
