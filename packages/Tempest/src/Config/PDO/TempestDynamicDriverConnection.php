<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config\PDO;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Driver\PDO\Result;
use Doctrine\DBAL\Driver\PDO\Statement;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use PDO;
use PDOException;

/**
 * A Doctrine DriverConnection that re-resolves Tempest's default Connection singleton
 * on every DBAL call. This allows the DbalTransactionInterceptor to transparently follow
 * whichever PDO TempestTenantDatabaseSwitcher has promoted as the current default,
 * so Ecotone transactions wrap Tempest ORM writes even when the active connection changes
 * between messages (multi-tenant switching).
 *
 * licence Apache-2.0
 */
final class TempestDynamicDriverConnection implements DriverConnection
{
    private ?PDO $transactionPdo = null;

    private function pdo(): PDO
    {
        if ($this->transactionPdo !== null) {
            return $this->transactionPdo;
        }

        return TempestConnectionPdo::connect(null);
    }

    public function prepare(string $sql): StatementInterface
    {
        try {
            return new Statement($this->pdo()->prepare($sql));
        } catch (PDOException $e) {
            throw Exception::new($e);
        }
    }

    public function query(string $sql): ResultInterface
    {
        try {
            return new Result($this->pdo()->query($sql));
        } catch (PDOException $e) {
            throw Exception::new($e);
        }
    }

    public function quote(string $value): string
    {
        return $this->pdo()->quote($value);
    }

    public function exec(string $sql): int|string
    {
        try {
            $result = $this->pdo()->exec($sql);
        } catch (PDOException $e) {
            throw Exception::new($e);
        }

        return $result === false ? 0 : $result;
    }

    public function lastInsertId(): int|string
    {
        try {
            return $this->pdo()->lastInsertId();
        } catch (PDOException $e) {
            throw Exception::new($e);
        }
    }

    public function beginTransaction(): void
    {
        try {
            $pdo = $this->pdo();
            $pdo->beginTransaction();
        } catch (PDOException $e) {
            throw Exception::new($e);
        }
        $this->transactionPdo = $pdo;
    }

    public function commit(): void
    {
        try {
            $this->pdo()->commit();
        } catch (PDOException $e) {
            throw Exception::new($e);
        } finally {
            $this->transactionPdo = null;
        }
    }

    public function rollBack(): void
    {
        try {
            $this->pdo()->rollBack();
        } catch (PDOException $e) {
            throw Exception::new($e);
        } finally {
            $this->transactionPdo = null;
        }
    }

    public function getNativeConnection(): PDO
    {
        return $this->pdo();
    }

    public function getServerVersion(): string
    {
        return $this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }
}
