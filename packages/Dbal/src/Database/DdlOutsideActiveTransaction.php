<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * MySQL/MariaDB implicitly commit the surrounding transaction on any DDL statement, breaking the later
 * commit/rollback the message transaction expects. Committing explicitly, running the DDL, then reopening
 * the transaction on the same connection keeps that implicit commit intentional instead, without a second
 * connection racing the first over the table's metadata (MySQL error 1412).
 *
 * licence Apache-2.0
 */
final class DdlOutsideActiveTransaction
{
    /**
     * @param string|array<string> $statements
     */
    public static function execute(Connection $connection, string|array $statements): void
    {
        self::run($connection, function (Connection $ddlConnection) use ($statements): void {
            foreach (is_array($statements) ? $statements : [$statements] as $statement) {
                $ddlConnection->executeStatement($statement);
            }
        });
    }

    public static function run(Connection $connection, callable $ddlOperation): void
    {
        $reopenTransactionAfterDdl = $connection->isTransactionActive() && $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;

        if ($reopenTransactionAfterDdl) {
            $connection->commit();
        }

        try {
            $ddlOperation($connection);
        } finally {
            if ($reopenTransactionAfterDdl) {
                $connection->beginTransaction();
            }
        }
    }
}
