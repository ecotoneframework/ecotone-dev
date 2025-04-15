<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Compatibility;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;

/**
 * @package Ecotone\Dbal\Compatibility
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * Compatibility layer for DBAL 3.x and 4.x schema manager methods
 */
final class SchemaManagerCompatibility
{
    /**
     * Compatibility method for _getPortableTableColumnDefinition
     * This method is called when creating tables and needs to handle the requiresSQLCommentHint method
     */
    public static function fixColumnComment(Column $column): void
    {
        try {
            if (DbalTypeCompatibility::isDbal4()) {
                // In DBAL 4.x, type information is stored in the schema, not in comments
                // However, we might still want to add a comment for backward compatibility
                // with tools that read the comments
                try {
                    $type = $column->getType();
                    $comment = $column->getComment() ?: '';
                    $typeComment = '(DC2Type:' . $type->getName() . ')';

                    // Only add the comment if it's not already there
                    if ($comment === '') {
                        // In DBAL 4.x, we don't need to set the comment, but we can if we want
                        // for backward compatibility
                        // $column->setComment($typeComment);
                    } elseif (!str_contains($comment, $typeComment)) {
                        // If there's already a comment, we can append the type information
                        // $column->setComment($comment . ' ' . $typeComment);
                    }
                } catch (\Throwable $e) {
                    // If there's an error getting the type or setting the comment, just ignore it
                }
                return;
            }

            // In DBAL 3.x, we need to set the comment if the type requires it
            try {
                $type = $column->getType();
                if (DbalTypeCompatibility::requiresSQLCommentHint($type)) {
                    $comment = $column->getComment() ?: '';
                    $typeComment = '(DC2Type:' . $type->getName() . ')';

                    if ($comment === '') {
                        $column->setComment($typeComment);
                    } elseif (!str_contains($comment, $typeComment)) {
                        $column->setComment($comment . ' ' . $typeComment);
                    }
                }
            } catch (\Throwable $e) {
                // If there's an error getting the type or setting the comment, just ignore it
            }
        } catch (\Throwable $e) {
            // If there's an error, just ignore it
        }
    }

    /**
     * Get the schema manager from a connection, handling both DBAL 3.x and 4.x
     */
    public static function getSchemaManager($connection): object
    {
        try {
            // Try DBAL 3.x method first
            if (method_exists($connection, 'getSchemaManager')) {
                return $connection->getSchemaManager();
            }

            // Then try DBAL 4.x method
            if (method_exists($connection, 'createSchemaManager')) {
                return $connection->createSchemaManager();
            }

            // If neither method exists, throw an exception
            throw new \RuntimeException('Could not get schema manager from connection');
        } catch (\Throwable $e) {
            // If there's an error, re-throw it
            throw $e;
        }
    }
}
