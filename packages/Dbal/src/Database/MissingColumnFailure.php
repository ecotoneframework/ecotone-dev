<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Ecotone\Messaging\Config\ConfigurationException;
use Throwable;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class MissingColumnFailure
{
    private const FLOW_COLUMNS = ['flow_type', 'flow_status'];
    private const SQLITE_MESSAGES = ['no such column', 'has no column named'];

    public static function explained(Throwable $failure, SpansTableManager $tableManager): Throwable
    {
        return self::ofColumns(failure: $failure, columns: self::FLOW_COLUMNS, instructions: $tableManager->getMissingColumnsInstructions());
    }

    /**
     * @param list<string> $columns
     */
    public static function ofColumns(Throwable $failure, array $columns, string $instructions): Throwable
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if (self::isAboutAColumnMissing($cause, $columns)) {
                return new ConfigurationException($instructions, ConfigurationException::CONFIGURATION_EXCEPTION, $failure);
            }
        }

        return $failure;
    }

    /**
     * @param list<string> $columns
     */
    private static function isAboutAColumnMissing(Throwable $cause, array $columns): bool
    {
        if (! $cause instanceof DriverException) {
            return false;
        }

        $namesOneOfTheColumns = array_filter($columns, fn (string $column): bool => str_contains($cause->getMessage(), $column)) !== [];
        $isMissingColumn = $cause instanceof InvalidFieldNameException || array_filter(self::SQLITE_MESSAGES, fn (string $message): bool => str_contains($cause->getMessage(), $message)) !== [];

        return $namesOneOfTheColumns && $isMissingColumn;
    }
}
