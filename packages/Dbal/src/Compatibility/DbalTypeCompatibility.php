<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Compatibility;

use Doctrine\DBAL\Types\Type;

/**
 * @package Ecotone\Dbal\Compatibility
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * Compatibility layer for DBAL 3.x and 4.x
 */
final class DbalTypeCompatibility
{
    private static ?bool $isDbal4 = null;

    /**
     * Check if we're using DBAL 4.x
     */
    public static function isDbal4(): bool
    {
        if (self::$isDbal4 === null) {
            // Check if the requiresSQLCommentHint method exists in the Type class
            self::$isDbal4 = !method_exists(Type::class, 'requiresSQLCommentHint');
        }

        return self::$isDbal4;
    }

    /**
     * Compatibility method for requiresSQLCommentHint
     * In DBAL 3.x, this method exists on the Type class
     * In DBAL 4.x, this method was removed
     */
    public static function requiresSQLCommentHint(Type $type): bool
    {
        if (self::isDbal4()) {
            // In DBAL 4.x, this method was removed and is no longer needed
            // The type information is now stored in the schema
            return false;
        }

        // In DBAL 3.x, call the method directly
        try {
            return $type->requiresSQLCommentHint();
        } catch (\Error $e) {
            // If the method doesn't exist, return false
            return false;
        }
    }
}
