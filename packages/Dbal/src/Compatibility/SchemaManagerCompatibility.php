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
                // In DBAL 4.x, we don't need to do anything
                return;
            }

            // In DBAL 3.x, we need to set the comment if the type requires it
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
        } catch (\Error $e) {
            // If there's an error, just ignore it
        }
    }
}
