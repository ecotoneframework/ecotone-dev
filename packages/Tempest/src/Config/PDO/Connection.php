<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config\PDO;

use function assert;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Driver\PDO\Result;
use Doctrine\DBAL\Driver\PDO\Statement;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ServerVersionProvider;
use PDO;
use PDOException;
use PDOStatement;

/**
 * licence Apache-2.0
 */
final class Connection implements DriverConnection, ServerVersionProvider
{
    public function __construct(private PDO $pdo)
    {
    }

    public function exec(string $statement): int
    {
        try {
            $result = $this->pdo->exec($statement);
            assert($result !== false);

            return $result;
        } catch (PDOException $e) {
            throw Exception::new($e);
        }
    }

    public function prepare(string $sql): StatementInterface
    {
        try {
            return new Statement($this->pdo->prepare($sql));
        } catch (PDOException $e) {
            throw Exception::new($e);
        }
    }

    public function query(string $sql): ResultInterface
    {
        try {
            $stmt = $this->pdo->query($sql);
            assert($stmt instanceof PDOStatement);

            return new Result($stmt);
        } catch (PDOException $e) {
            throw Exception::new($e);
        }
    }

    public function lastInsertId(): string|int
    {
        try {
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            throw Exception::new($e);
        }
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    public function quote(string $value): string
    {
        return $this->pdo->quote($value);
    }

    public function getServerVersion(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    public function getNativeConnection(): PDO
    {
        return $this->pdo;
    }
}
