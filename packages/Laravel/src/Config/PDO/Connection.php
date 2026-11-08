<?php

namespace Ecotone\Laravel\Config\PDO;

use function assert;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Driver\PDO\Result;
use Doctrine\DBAL\Driver\PDO\Statement;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
// No need for version detection, we'll implement the DBAL 4.x interface
use Doctrine\DBAL\ServerVersionProvider;
use Illuminate\Database\Connection as LaravelConnection;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * This file is a modified version of a class from the Laravel framework.
 *
 * Laravel is licensed under the MIT License.
 * Original authors: Taylor Otwell and the Laravel contributors.
 *
 * @license MIT (https://opensource.org/licenses/MIT)
 *
 * Modifications were made as part of the Ecotone framework under the Apache 2.0 License.
 * See LICENSE file for the Apache 2.0 License details.
 */
/**
 * licence Apache-2.0
 */
class Connection implements DriverConnection, ServerVersionProvider
{
    private ?int $laravelTransactionLevelBeforeBegin = null;

    public function __construct(private LaravelConnection $laravelConnection)
    {
    }

    public function exec(string $statement): int
    {
        try {
            $result = $this->pdo()->exec($statement);

            assert($result !== false);

            return $result;
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function prepare(string $sql): StatementInterface
    {
        try {
            return new Statement($this->pdo()->prepare($sql));
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function query(string $sql): ResultInterface
    {
        try {
            $stmt = $this->pdo()->query($sql);

            assert($stmt instanceof PDOStatement);

            return new Result($stmt);
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function lastInsertId(): string|int
    {
        try {
            return $this->pdo()->lastInsertId();
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function beginTransaction(): void
    {
        $levelBeforeBegin = $this->laravelConnection->transactionLevel();
        try {
            $this->laravelConnection->beginTransaction();
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
        $this->laravelTransactionLevelBeforeBegin = $levelBeforeBegin;
    }

    public function commit(): void
    {
        try {
            $this->laravelConnection->commit();
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
        $this->laravelTransactionLevelBeforeBegin = null;
    }

    public function rollBack(): void
    {
        try {
            $this->laravelConnection->rollBack();
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
        $this->laravelTransactionLevelBeforeBegin = null;
    }

    public function endLostTransaction(): void
    {
        if ($this->laravelTransactionLevelBeforeBegin === null) {
            return;
        }

        $levelBeforeBegin = $this->laravelTransactionLevelBeforeBegin;
        $this->laravelTransactionLevelBeforeBegin = null;
        while ($this->laravelConnection->transactionLevel() > $levelBeforeBegin) {
            try {
                $this->laravelConnection->rollBack();
            } catch (Throwable) {
                break;
            }
        }
        if ($this->laravelConnection->transactionLevel() === 0) {
            $this->laravelConnection->disconnect();
        }
    }

    public function quote(string $value): string
    {
        return $this->pdo()->quote($value);
    }

    public function getServerVersion(): string
    {
        return $this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    public function getNativeConnection(): PDO
    {
        return $this->pdo();
    }

    private function pdo(): PDO
    {
        $this->laravelConnection->reconnectIfMissingConnection();

        return $this->laravelConnection->getPdo();
    }
}
