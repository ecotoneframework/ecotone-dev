<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Compatibility;

use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Exception;
use InvalidArgumentException;
use ReflectionMethod;

/**
 * @package Ecotone\Dbal\Compatibility
 * @author Łukasz Adamczewski <tworzenieweb@gmail.com>
 *
 * Simple proxy class to keep the QueryBuilder API compatible with Doctrine DBAL 3.0, and 4.0
 * This class delegates all calls to the wrapped QueryBuilder instance.
 * Class supports execution of various fetch methods directly from query object that was added in version 3.1
 *
 * @see https://github.com/doctrine/dbal/blob/3.6.x/UPGRADE.md#upgrade-to-31
 */
/**
 * licence Apache-2.0
 */
final class QueryBuilderProxy
{
    private QueryBuilder $queryBuilder;
    private bool $isDbal4;

    public function __construct(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
        $this->isDbal4 = $this->detectDbal4();
    }

    /**
     * Detects if we're using DBAL 4.x
     */
    private function detectDbal4(): bool
    {
        // Check if the select method has the DBAL 4 signature (with variadic string parameters)
        try {
            $reflectionMethod = new ReflectionMethod(QueryBuilder::class, 'select');
            $parameters = $reflectionMethod->getParameters();

            if (count($parameters) === 0) {
                return false;
            }

            // In DBAL 4, the first parameter is named 'expressions' and is variadic
            return $parameters[0]->isVariadic() && $parameters[0]->getName() === 'expressions';
        } catch (Exception $e) {
            // If we can't determine the version, assume it's DBAL 3
            return false;
        }
    }

    /**
     * Proxy for select method that works with both DBAL 3 and DBAL 4
     */
    public function select(/* mixed ...$args */): self
    {
        $args = func_get_args();

        if ($this->isDbal4) {
            // DBAL 4.x style: select(string ...$expressions)
            if (count($args) === 1 && is_array($args[0])) {
                // Handle array argument for DBAL 4
                // Convert array to variadic arguments
                $this->queryBuilder->select(...$args[0]);
            } else {
                $this->queryBuilder->select(...$args);
            }
        } else {
            // DBAL 3.x style: select($select = null)
            if (count($args) === 0) {
                $this->queryBuilder->select();
            } elseif (count($args) === 1 && is_array($args[0])) {
                $this->queryBuilder->select($args[0]);
            } else {
                $this->queryBuilder->select(...$args);
            }
        }

        return $this;
    }

    /**
     * Proxy for from method
     */
    public function from($from, $alias = null): self
    {
        $this->queryBuilder->from($from, $alias);

        return $this;
    }

    /**
     * Proxy for addSelect method that works with both DBAL 3 and DBAL 4
     */
    public function addSelect(/* mixed ...$args */): self
    {
        $args = func_get_args();

        if ($this->isDbal4) {
            // DBAL 4.x style: addSelect(string ...$expressions)
            $this->queryBuilder->addSelect(...$args);
        } else {
            // DBAL 3.x style: addSelect($select = null)
            if (count($args) === 0) {
                $this->queryBuilder->addSelect();
            } elseif (count($args) === 1 && is_array($args[0])) {
                $this->queryBuilder->addSelect($args[0]);
            } else {
                $this->queryBuilder->addSelect(...$args);
            }
        }

        return $this;
    }

    /**
     * Proxy for delete method
     */
    public function delete($delete = null, $alias = null): self
    {
        $this->queryBuilder->delete($delete, $alias);

        return $this;
    }

    /**
     * Proxy for update method
     */
    public function update($update = null, $alias = null): self
    {
        $this->queryBuilder->update($update, $alias);

        return $this;
    }

    /**
     * Proxy for set method
     */
    public function set($key, $value): self
    {
        $this->queryBuilder->set($key, $value);

        return $this;
    }

    /**
     * Proxy for where method that works with both DBAL 3 and DBAL 4
     */
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

    /**
     * Proxy for andWhere method that works with both DBAL 3 and DBAL 4
     */
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

    /**
     * Proxy for orWhere method that works with both DBAL 3 and DBAL 4
     */
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

    /**
     * Proxy for groupBy method that works with both DBAL 3 and DBAL 4
     */
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

    /**
     * Proxy for addGroupBy method that works with both DBAL 3 and DBAL 4
     */
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

    /**
     * Proxy for having method that works with both DBAL 3 and DBAL 4
     */
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

    /**
     * Proxy for setFirstResult method
     */
    public function setFirstResult($firstResult): self
    {
        $this->queryBuilder->setFirstResult($firstResult);

        return $this;
    }

    /**
     * Proxy for setMaxResults method
     */
    public function setMaxResults($maxResults): self
    {
        $this->queryBuilder->setMaxResults($maxResults);

        return $this;
    }

    /**
     * Proxy for setParameter method
     */
    public function setParameter($key, $value, $type = null): self
    {
        $this->queryBuilder->setParameter($key, $value, $type);

        return $this;
    }

    /**
     * Proxy for setParameters method
     */
    public function setParameters(array $params, array $types = []): self
    {
        $this->queryBuilder->setParameters($params, $types);

        return $this;
    }

    /**
     * Proxy for __clone method
     */
    public function __clone(): void
    {
        $this->queryBuilder = clone $this->queryBuilder;
    }

    /**
     * Proxy for __toString method
     */
    public function __toString(): string
    {
        return $this->queryBuilder->__toString();
    }

    /**
     * Proxy for expr method
     */
    public function expr(): ExpressionBuilder
    {
        return $this->queryBuilder->expr();
    }

    /**
     * Proxy for resetQueryParts method
     */
    public function resetQueryParts($queryPartNames = null): self
    {
        $this->queryBuilder->resetQueryParts($queryPartNames);

        return $this;
    }

    /**
     * Proxy for getQueryPart method
     */
    public function getQueryPart($queryPartName): mixed
    {
        return $this->queryBuilder->getQueryPart($queryPartName);
    }

    /**
     * Proxy for getSQL method
     */
    public function getSQL(): string
    {
        return $this->queryBuilder->getSQL();
    }

    /**
     * Proxy for getType method
     */
    public function getType(): int
    {
        return $this->queryBuilder->getType();
    }

    /**
     * Proxy for getState method
     */
    public function getState(): int
    {
        return $this->queryBuilder->getState();
    }

    /**
     * Proxy for execute method
     */
    public function execute(): mixed
    {
        // In DBAL 4.x, execute() is deprecated and split into executeQuery() and executeStatement()
        // In DBAL 3.x, execute() returns a Result object for SELECT queries
        // and an integer for UPDATE/DELETE/INSERT queries
        if (method_exists($this->queryBuilder, 'execute')) {
            return $this->queryBuilder->execute();
        } else {
            // In DBAL 4.x, execute() is split into executeQuery() and executeStatement()
            return $this->queryBuilder->executeQuery();
        }
    }

    /**
     * Proxy for executeQuery method
     */
    public function executeQuery(): mixed
    {
        // In DBAL 4.x, this is the preferred method for SELECT queries
        // In DBAL 3.x, we need to fall back to execute()
        $name = method_exists($this->queryBuilder, 'executeQuery') ? 'executeQuery' : 'execute';

        return $this->queryBuilder->{$name}();
    }

    /**
     * Proxy for executeStatement method
     */
    public function executeStatement(): mixed
    {
        // In DBAL 4.x, this is the preferred method for UPDATE/DELETE/INSERT queries
        // In DBAL 3.x, we need to fall back to execute()
        $name = method_exists($this->queryBuilder, 'executeStatement') ? 'executeStatement' : 'execute';

        return $this->queryBuilder->{$name}();
    }

    /**
     * Magic method to handle fetch methods and other methods not explicitly defined
     */
    public function __call($name, $arguments)
    {
        // Handle fetch methods that were added in DBAL 3.1
        switch ($name) {
            case 'fetchAllAssociativeIndexed':
            case 'fetchAllKeyValue':
            case 'fetchAllNumeric':
            case 'fetchAssociative':
            case 'fetchNumeric':
            case 'fetchAllAssociative':
            case 'fetchFirstColumn':
            case 'fetchOne':
                return $this->execute()->$name(...$arguments);
        }

        // Try to call the method on the wrapped QueryBuilder
        if (method_exists($this->queryBuilder, $name)) {
            $result = $this->queryBuilder->$name(...$arguments);
            return ($result === $this->queryBuilder) ? $this : $result;
        }

        // Special handling for methods that might not exist in all DBAL versions
        if ($name === 'execute') {
            return $this->execute();
        }

        throw new InvalidArgumentException(sprintf('Not supported proxy method: %s', $name));
    }
}
