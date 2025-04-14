<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Compatibility;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * @package Ecotone\Dbal\Compatibility
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * Compatibility layer for DBAL 3.x and 4.x platform methods
 */
final class PlatformCompatibility
{
    /**
     * Compatibility method for getDoctrineTypeComment
     * In DBAL 3.x, this method calls requiresSQLCommentHint on the Type
     * In DBAL 4.x, this method was changed
     */
    public static function getDoctrineTypeComment(AbstractPlatform $platform, Type $type): string
    {
        try {
            if (DbalTypeCompatibility::isDbal4()) {
                // In DBAL 4.x, we don't need to call requiresSQLCommentHint
                return $platform->getDoctrineTypeComment($type);
            }

            // In DBAL 3.x, we need to check if the type requires a SQL comment hint
            if (DbalTypeCompatibility::requiresSQLCommentHint($type)) {
                return $platform->getDoctrineTypeComment($type);
            }

            return '';
        } catch (\Error $e) {
            // If there's an error, return an empty string
            return '';
        }
    }
}
