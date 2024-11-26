<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture;

use Closure;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Statement;
use Traversable;

/**
 * licence Apache-2.0
 */
final class FailingConnection extends Connection
{
    /**
     * @param Connection $connection
     * @param array $connectionFailuresOnCommit any true will cause connection to fail on commit
     * @param array $connectionFailuresOnRollBack any true will cause connection to fail on roll back
     */
    public function __construct(
        private Connection $connection,
        private array $connectionFailuresOnRollBack = [],
        private array $connectionFailuresOnCommit = [],
        private array $connectionFailureOnStoreInDeadLetter = [],
        private array $connectionFailureOnMessageAcknowledge = [],
    ) {

    }

    public function rollBack()
    {
        $connectionFailure = array_shift($this->connectionFailuresOnRollBack);
        if ($connectionFailure) {
            $this->connection->close();
            throw new ConnectionException('Database connection gone away');
        }

        return $this->connection->rollBack();
    }

    public function commit()
    {
        $connectionFailure = array_shift($this->connectionFailuresOnCommit);
        if ($connectionFailure) {
            $this->connection->close();
            throw new ConnectionException('Database connection gone away');
        }

        return $this->connection->commit();
    }

    public function getParams()
    {
        return $this->connection->getParams();
    }

    public function getDatabase()
    {
        return $this->connection->getDatabase();
    }

    public function getDriver()
    {
        return $this->connection->getDriver();
    }

    public function getConfiguration()
    {
        return $this->connection->getConfiguration();
    }

    public function getEventManager()
    {
        return $this->connection->getEventManager();
    }

    public function getDatabasePlatform()
    {
        return $this->connection->getDatabasePlatform();
    }

    public function createExpressionBuilder(): ExpressionBuilder
    {
        return $this->connection->createExpressionBuilder();
    }

    public function getExpressionBuilder()
    {
        return $this->connection->getExpressionBuilder();
    }

    public function connect()
    {
        return $this->connection->connect();
    }

    public function isAutoCommit()
    {
        return $this->connection->isAutoCommit();
    }

    public function setAutoCommit($autoCommit)
    {
        return $this->connection->setAutoCommit($autoCommit);
    }

    public function fetchAssociative(string $query, array $params = [], array $types = [])
    {
        return $this->connection->fetchAssociative($query, $params, $types);
    }

    public function fetchNumeric(string $query, array $params = [], array $types = [])
    {
        return $this->connection->fetchNumeric($query, $params, $types);
    }

    public function fetchOne(string $query, array $params = [], array $types = [])
    {
        return $this->connection->fetchOne($query, $params, $types);
    }

    public function isConnected()
    {
        return $this->connection->isConnected();
    }

    public function isTransactionActive()
    {
        return $this->connection->isTransactionActive();
    }

    public function delete($table, array $criteria, array $types = [])
    {
        if ($table === 'enqueue') {
            $connectionFailure = array_shift($this->connectionFailureOnMessageAcknowledge);
            if ($connectionFailure) {
                $this->connection->close();
                throw new ConnectionException('Database connection gone away');
            }
        }

        return $this->connection->delete($table, $criteria, $types);
    }

    public function close()
    {
        $this->connection->close();
    }

    public function setTransactionIsolation($level)
    {
        return $this->connection->setTransactionIsolation($level);
    }

    public function getTransactionIsolation()
    {
        return $this->connection->getTransactionIsolation();
    }

    public function update($table, array $data, array $criteria, array $types = [])
    {
        return $this->connection->update($table, $data, $criteria, $types);
    }

    public function insert($table, array $data, array $types = [])
    {
        if ($table === 'ecotone_error_messages') {
            $connectionFailure = array_shift($this->connectionFailureOnStoreInDeadLetter);
            if ($connectionFailure) {
                $this->connection->close();
                throw new ConnectionException('Database connection gone away');
            }
        }

        return $this->connection->insert($table, $data, $types);
    }

    public function quoteIdentifier($str)
    {
        return $this->connection->quoteIdentifier($str);
    }

    public function quote($value, $type = ParameterType::STRING)
    {
        return $this->connection->quote($value, $type);
    }

    public function fetchAllNumeric(string $query, array $params = [], array $types = []): array
    {
        return $this->connection->fetchAllNumeric($query, $params, $types);
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        return $this->connection->fetchAllAssociative($query, $params, $types);
    }

    public function fetchAllKeyValue(string $query, array $params = [], array $types = []): array
    {
        return $this->connection->fetchAllKeyValue($query, $params, $types);
    }

    public function fetchAllAssociativeIndexed(string $query, array $params = [], array $types = []): array
    {
        return $this->connection->fetchAllAssociativeIndexed($query, $params, $types);
    }

    public function fetchFirstColumn(string $query, array $params = [], array $types = []): array
    {
        return $this->connection->fetchFirstColumn($query, $params, $types);
    }

    public function iterateNumeric(string $query, array $params = [], array $types = []): Traversable
    {
        return $this->connection->iterateNumeric($query, $params, $types);
    }

    public function iterateAssociative(string $query, array $params = [], array $types = []): Traversable
    {
        return $this->connection->iterateAssociative($query, $params, $types);
    }

    public function iterateKeyValue(string $query, array $params = [], array $types = []): Traversable
    {
        return $this->connection->iterateKeyValue($query, $params, $types);
    }

    public function iterateAssociativeIndexed(string $query, array $params = [], array $types = []): Traversable
    {
        return $this->connection->iterateAssociativeIndexed($query, $params, $types);
    }

    public function iterateColumn(string $query, array $params = [], array $types = []): Traversable
    {
        return $this->connection->iterateColumn($query, $params, $types);
    }

    public function prepare(string $sql): Statement
    {
        return $this->connection->prepare($sql);
    }

    public function executeQuery(string $sql, array $params = [], $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        return $this->connection->executeQuery($sql, $params, $types, $qcp);
    }

    public function executeCacheQuery($sql, $params, $types, QueryCacheProfile $qcp): Result
    {
        return $this->connection->executeCacheQuery($sql, $params, $types, $qcp);
    }

    public function executeStatement($sql, array $params = [], array $types = [])
    {
        return $this->connection->executeStatement($sql, $params, $types);
    }

    public function getTransactionNestingLevel()
    {
        return $this->connection->getTransactionNestingLevel();
    }

    public function lastInsertId($name = null)
    {
        return $this->connection->lastInsertId($name);
    }

    public function transactional(Closure $func)
    {
        return $this->connection->transactional($func);
    }

    public function setNestTransactionsWithSavepoints($nestTransactionsWithSavepoints)
    {
        $this->connection->setNestTransactionsWithSavepoints($nestTransactionsWithSavepoints);
    }

    public function getNestTransactionsWithSavepoints()
    {
        return $this->connection->getNestTransactionsWithSavepoints();
    }

    protected function _getNestedTransactionSavePointName()
    {
        return $this->connection->_getNestedTransactionSavePointName();
    }

    public function beginTransaction()
    {
        return $this->connection->beginTransaction();
    }

    public function createSavepoint($savepoint)
    {
        $this->connection->createSavepoint($savepoint);
    }

    public function releaseSavepoint($savepoint)
    {
        return $this->connection->releaseSavepoint($savepoint);
    }

    public function rollbackSavepoint($savepoint)
    {
        $this->connection->rollbackSavepoint($savepoint);
    }

    public function getWrappedConnection()
    {
        return $this->connection->getWrappedConnection();
    }

    public function getNativeConnection()
    {
        return $this->connection->getNativeConnection();
    }

    public function createSchemaManager(): AbstractSchemaManager
    {
        return $this->connection->createSchemaManager();
    }

    public function getSchemaManager()
    {
        return $this->connection->getSchemaManager();
    }

    public function setRollbackOnly()
    {
        $this->connection->setRollbackOnly();
    }

    public function isRollbackOnly()
    {
        return $this->connection->isRollbackOnly();
    }

    public function convertToDatabaseValue($value, $type)
    {
        return $this->connection->convertToDatabaseValue($value, $type);
    }

    public function convertToPHPValue($value, $type)
    {
        return $this->connection->convertToPHPValue($value, $type);
    }

    public function createQueryBuilder()
    {
        return $this->connection->createQueryBuilder();
    }

    public function executeUpdate(string $sql, array $params = [], array $types = []): int
    {
        return $this->connection->executeUpdate($sql, $params, $types);
    }

    public function query(string $sql): Result
    {
        return $this->connection->query($sql);
    }

    public function exec(string $sql): int
    {
        return $this->connection->exec($sql);
    }
}
