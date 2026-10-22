<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class TimeBoxedSchemaRead
{
    private const SAVEPOINT = 'ecotone_time_boxed_schema_read';

    /**
     * @template T
     * @param callable(): T $read
     * @return T
     */
    public static function within(Connection $connection, int $milliseconds, callable $read): mixed
    {
        $milliseconds = max(1, $milliseconds);
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform => self::onPostgreSql($connection, $milliseconds, $read),
            $platform instanceof MariaDBPlatform => self::onSessionVariable($connection, 'max_statement_time', sprintf('%.3F', $milliseconds / 1000), $read),
            $platform instanceof MySQLPlatform => self::onSessionVariable($connection, 'max_execution_time', (string) $milliseconds, $read),
            default => $read(),
        };
    }

    private static function onPostgreSql(Connection $connection, int $milliseconds, callable $read): mixed
    {
        $inTransaction = $connection->isTransactionActive();
        if ($inTransaction) {
            $connection->createSavepoint(self::SAVEPOINT);
        } else {
            $connection->beginTransaction();
        }

        try {
            $connection->executeStatement(sprintf('SET LOCAL statement_timeout = %d', $milliseconds));

            return $read();
        } finally {
            if ($inTransaction) {
                $connection->rollbackSavepoint(self::SAVEPOINT);
            } else {
                $connection->rollBack();
            }
        }
    }

    private static function onSessionVariable(Connection $connection, string $variable, string $value, callable $read): mixed
    {
        $previous = (string) $connection->fetchOne(sprintf('SELECT @@SESSION.%s', $variable));
        $connection->executeStatement(sprintf('SET SESSION %s = %s', $variable, $value));

        try {
            return $read();
        } finally {
            self::restoreWhileTheConnectionLives($connection, $variable, $previous);
        }
    }

    private static function restoreWhileTheConnectionLives(Connection $connection, string $variable, string $previous): void
    {
        try {
            $connection->executeStatement(sprintf('SET SESSION %s = %s', $variable, $previous));
        } catch (DbalException) {
        }
    }
}
