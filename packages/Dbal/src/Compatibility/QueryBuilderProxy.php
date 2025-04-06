<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Compatibility;

use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use InvalidArgumentException;

/**
 * @package Ecotone\Dbal\Compatibility
 * @author Łukasz Adamczewski <tworzenieweb@gmail.com>
 *
 * Simple proxy class to keep the QueryBuilder API compatible with Doctrine DBAL 2.10 and 3.0
 * All the parent methods need to be implemented in order to intercept and pass to the wrapped instance.
 * Class supports execution of various fetch methods directly from query object that was added in version 3.1
 *
 * @see https://github.com/doctrine/dbal/blob/3.6.x/UPGRADE.md#upgrade-to-31
 */
/**
 * licence Apache-2.0
 */
final class QueryBuilderProxy extends QueryBuilder
{
    private QueryBuilder $queryBuilder;

    public function __construct(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;

        // In DBAL 4.x, the QueryBuilder constructor requires a Connection object
        // We need to get the connection from the queryBuilder
        try {
            // First try using reflection to get the connection property
            $reflectionClass = new \ReflectionClass($queryBuilder);
            if ($reflectionClass->hasProperty('connection')) {
                $connectionProperty = $reflectionClass->getProperty('connection');
                $connectionProperty->setAccessible(true);
                $connection = $connectionProperty->getValue($queryBuilder);
                parent::__construct($connection);
            } else {
                // Try using getConnection method if available
                if (method_exists($queryBuilder, 'getConnection')) {
                    parent::__construct($queryBuilder->getConnection());
                } else {
                    // Last resort fallback - try to construct without connection
                    // This will likely fail in DBAL 4.x but might work in 3.x
                    parent::__construct();
                }
            }
        } catch (\Exception $e) {
            // If all else fails, try one more approach
            try {
                if (method_exists($queryBuilder, 'getConnection')) {
                    parent::__construct($queryBuilder->getConnection());
                } else {
                    // Last resort fallback
                    parent::__construct();
                }
            } catch (\Exception $e) {
                // We've tried our best, let the error propagate
                throw $e;
            }
        }
    }

    // override all public methods from parent class with empty body
    public function select(string ...$expressions): self
    {
        // Handle both DBAL 3.x and 4.x versions
        if (count($expressions) === 0) {
            // DBAL 3.x style: select()
            $this->queryBuilder->select();
        } else {
            // DBAL 4.x style: select(string ...)
            $this->queryBuilder->select(...$expressions);
        }

        return $this;
    }

    public function from($from, $alias = null): self
    {
        $this->queryBuilder->{__FUNCTION__}($from, $alias);

        return $this;
    }

    public function addSelect(string ...$expressions): self
    {
        // Handle both DBAL 3.x and 4.x versions
        if (count($expressions) === 0) {
            // DBAL 3.x style: addSelect()
            $this->queryBuilder->addSelect();
        } else {
            // DBAL 4.x style: addSelect(string ...)
            $this->queryBuilder->addSelect(...$expressions);
        }

        return $this;
    }

    public function delete($delete = null, $alias = null): self
    {
        $this->queryBuilder->{__FUNCTION__}($delete, $alias);

        return $this;
    }

    public function update($update = null, $alias = null): self
    {
        $this->queryBuilder->{__FUNCTION__}($update, $alias);

        return $this;
    }

    public function set($key, $value): self
    {
        $this->queryBuilder->{__FUNCTION__}($key, $value);

        return $this;
    }

    public function where($predicate, ...$predicates): self
    {
        // Handle both DBAL 3.x and 4.x versions
        if (empty($predicates)) {
            // DBAL 3.x style: where($predicates)
            $this->queryBuilder->where($predicate);
        } else {
            // DBAL 4.x style: where($predicate, ...$predicates)
            $this->queryBuilder->where($predicate, ...$predicates);
        }

        return $this;
    }

    public function andWhere($predicate, ...$predicates): self
    {
        // Handle both DBAL 3.x and 4.x versions
        if (empty($predicates)) {
            // DBAL 3.x style: andWhere($where)
            $this->queryBuilder->andWhere($predicate);
        } else {
            // DBAL 4.x style: andWhere($predicate, ...$predicates)
            $this->queryBuilder->andWhere($predicate, ...$predicates);
        }

        return $this;
    }

    public function orWhere($predicate, ...$predicates): self
    {
        // Handle both DBAL 3.x and 4.x versions
        if (empty($predicates)) {
            // DBAL 3.x style: orWhere($where)
            $this->queryBuilder->orWhere($predicate);
        } else {
            // DBAL 4.x style: orWhere($predicate, ...$predicates)
            $this->queryBuilder->orWhere($predicate, ...$predicates);
        }

        return $this;
    }

    public function groupBy($groupBy, ...$groupBys): self
    {
        // Handle both DBAL 3.x and 4.x versions
        if (empty($groupBys)) {
            // DBAL 3.x style: groupBy($groupBy)
            $this->queryBuilder->groupBy($groupBy);
        } else {
            // DBAL 4.x style: groupBy($groupBy, ...$groupBys)
            $this->queryBuilder->groupBy($groupBy, ...$groupBys);
        }

        return $this;
    }

    public function addGroupBy($groupBy, ...$groupBys): self
    {
        // Handle both DBAL 3.x and 4.x versions
        if (empty($groupBys)) {
            // DBAL 3.x style: addGroupBy($groupBy)
            $this->queryBuilder->addGroupBy($groupBy);
        } else {
            // DBAL 4.x style: addGroupBy($groupBy, ...$groupBys)
            $this->queryBuilder->addGroupBy($groupBy, ...$groupBys);
        }

        return $this;
    }

    public function having($having, ...$havings): self
    {
        // Handle both DBAL 3.x and 4.x versions
        if (empty($havings)) {
            // DBAL 3.x style: having($having)
            $this->queryBuilder->having($having);
        } else {
            // DBAL 4.x style: having($having, ...$havings)
            $this->queryBuilder->having($having, ...$havings);
        }

        return $this;
    }

    public function setFirstResult($firstResult): self
    {
        $this->queryBuilder->{__FUNCTION__}($firstResult);

        return $this;
    }

    public function setMaxResults($maxResults): self
    {
        $this->queryBuilder->{__FUNCTION__}($maxResults);

        return $this;
    }

    public function setParameter($key, $value, $type = null): self
    {
        $this->queryBuilder->{__FUNCTION__}($key, $value, $type);

        return $this;
    }

    public function setParameters(array $params, array $types = []): self
    {
        $this->queryBuilder->{__FUNCTION__}($params, $types);

        return $this;
    }

    public function __clone(): void
    {
        $this->queryBuilder->{__FUNCTION__}();
    }

    public function __toString(): string
    {
        return $this->queryBuilder->{__FUNCTION__}();
    }

    public function expr(): ExpressionBuilder
    {
        return $this->queryBuilder->{__FUNCTION__}();
    }

    public function resetQueryParts($queryPartNames = null): self
    {
        $this->queryBuilder->{__FUNCTION__}($queryPartNames);

        return $this;
    }

    public function getQueryPart($queryPartName): mixed
    {
        return $this->queryBuilder->{__FUNCTION__}($queryPartName);
    }

    public function getSQL(): string
    {
        return $this->queryBuilder->{__FUNCTION__}();
    }

    public function getType(): int
    {
        return $this->queryBuilder->{__FUNCTION__}();
    }

    public function getState(): int
    {
        return $this->queryBuilder->{__FUNCTION__}();
    }

    public function execute(): Result|int|string
    {
        // In DBAL 4.x, execute() is deprecated and split into executeQuery() and executeStatement()
        // In DBAL 3.x, execute() returns a Result object for SELECT queries
        // and an integer for UPDATE/DELETE/INSERT queries
        return $this->queryBuilder->{__FUNCTION__}();
    }

    public function executeQuery(): Result
    {
        // In DBAL 4.x, this is the preferred method for SELECT queries
        // In DBAL 3.x, we need to fall back to execute()
        $name = method_exists($this->queryBuilder, __FUNCTION__) ? __FUNCTION__ : 'execute';

        return $this->queryBuilder->{$name}();
    }

    public function executeStatement(): int
    {
        // In DBAL 4.x, this is the preferred method for UPDATE/DELETE/INSERT queries
        // In DBAL 3.x, we need to fall back to execute()
        $name = method_exists($this->queryBuilder, __FUNCTION__) ? __FUNCTION__ : 'execute';

        return $this->queryBuilder->{$name}();
    }

    public function __call($name, $arguments)
    {
        switch ($name) {
            case 'fetchAllAssociativeIndexed':
            case 'fetchAllKeyValue':
            case 'fetchAllNumeric':
            case 'fetchAssociative':
            case 'fetchNumeric':
            case 'fetchAllAssociative':
            case 'fetchFirstColumn':
            case 'fetchOne':
                return $this->queryBuilder->execute()->$name(...$arguments);
        }

        throw new InvalidArgumentException(sprintf('Not supported proxy method: %s', $name));
    }
}
