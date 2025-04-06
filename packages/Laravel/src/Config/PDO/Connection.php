<?php

namespace Ecotone\Laravel\Config\PDO;

use function assert;

use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Driver\PDO\Result;
use Doctrine\DBAL\Driver\PDO\Statement;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\ServerVersionProvider;

// No need for version detection, we'll implement the DBAL 4.x interface
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use PDO;
use PDOException;
use PDOStatement;

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
    /**
     * The underlying PDO connection.
     *
     * @var PDO
     */
    protected $connection;

    /**
     * Create a new PDO connection instance.
     *
     * @param  PDO  $connection
     * @return void
     */
    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Execute an SQL statement.
     *
     * @param  string  $statement
     * @return int
     */
    public function exec(string $statement): int
    {
        try {
            $result = $this->connection->exec($statement);

            assert($result !== false);

            return $result;
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    /**
     * Prepare a new SQL statement.
     *
     * @param  string  $sql
     * @return StatementInterface
     *
     * @throws Exception
     */
    public function prepare(string $sql): StatementInterface
    {
        try {
            return $this->createStatement(
                $this->connection->prepare($sql)
            );
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    /**
     * Execute a new query against the connection.
     *
     * @param  string  $sql
     * @return ResultInterface
     */
    public function query(string $sql): ResultInterface
    {
        try {
            $stmt = $this->connection->query($sql);

            assert($stmt instanceof PDOStatement);

            return new Result($stmt);
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    /**
     * Get the last insert ID.
     *
     * @param  string|null  $name
     * @return mixed
     *
     * @throws Exception
     */
    public function lastInsertId(): string|int
    {
        try {
            return $this->connection->lastInsertId();
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    /**
     * Create a new statement instance.
     *
     * @param  PDOStatement  $stmt
     * @return Statement
     */
    protected function createStatement(PDOStatement $stmt): Statement
    {
        return new Statement($stmt);
    }

    /**
     * Begin a new database transaction.
     */
    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    /**
     * Commit a database transaction.
     */
    public function commit(): void
    {
        $this->connection->commit();
    }

    /**
     * Rollback a database transaction.
     */
    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    /**
     * Wrap quotes around the given input.
     *
     * @param  string  $input
     * @param  string  $type
     * @return string
     */
    public function quote(string $value): string
    {
        return $this->connection->quote($value);
    }

    /**
     * Get the server version for the connection.
     *
     * @return string
     */
    public function getServerVersion(): string
    {
        return $this->connection->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    /**
     * Get the native connection.
     *
     * @return PDO
     */
    public function getNativeConnection(): PDO
    {
        return $this->connection;
    }

    /**
     * Get the wrapped PDO connection.
     *
     * @return PDO
     */
    public function getWrappedConnection(): PDO
    {
        return $this->connection;
    }
}
