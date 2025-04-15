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
/**
 * licence Apache-2.0
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
            // Multiple checks to ensure accurate detection
            $checks = [
                // Check 1: requiresSQLCommentHint method doesn't exist in DBAL 4.x
                !method_exists(Type::class, 'requiresSQLCommentHint'),

                // Check 2: Try to get the version from the class constants if available
                self::checkVersionFromConstants(),

                // Check 3: Check for methods that only exist in DBAL 4.x
                method_exists('\\Doctrine\\DBAL\\Connection', 'createSchemaManager') &&
                !method_exists('\\Doctrine\\DBAL\\Connection', 'getSchemaManager')
            ];

            // If any check returns true, we're using DBAL 4.x
            self::$isDbal4 = in_array(true, $checks, true);
        }

        return self::$isDbal4;
    }

    /**
     * Try to determine DBAL version from class constants
     */
    private static function checkVersionFromConstants(): bool
    {
        // Check if we can get the version from the Connection class
        if (defined('\\Doctrine\\DBAL\\Connection::VERSION')) {
            $version = constant('\\Doctrine\\DBAL\\Connection::VERSION');
            return version_compare($version, '4.0.0', '>=');
        }

        // Check if we can get the version from the DriverManager class
        if (defined('\\Doctrine\\DBAL\\DriverManager::VERSION')) {
            $version = constant('\\Doctrine\\DBAL\\DriverManager::VERSION');
            return version_compare($version, '4.0.0', '>=');
        }

        // If we can't determine from constants, return null to use other checks
        return false;
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
