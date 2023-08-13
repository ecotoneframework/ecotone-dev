<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Compatibility;

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
final class QueryBuilderProxy extends QueryBuilder
{
    public function __construct(private QueryBuilder $queryBuilder)
    {
    }

    // override all public methods from parent class with empty body
    public function select($select = null)
    {
        $this->queryBuilder->{__FUNCTION__}(...func_get_args());

        return $this;
    }

    public function from($from, $alias = null)
    {
        $this->queryBuilder->{__FUNCTION__}($from, $alias);

        return $this;
    }

    public function addSelect($select = null)
    {
        $this->queryBuilder->{__FUNCTION__}(...func_get_args());

        return $this;
    }

    public function delete($delete = null, $alias = null)
    {
        $this->queryBuilder->{__FUNCTION__}($delete, $alias);

        return $this;
    }

    public function update($update = null, $alias = null)
    {
        $this->queryBuilder->{__FUNCTION__}($update, $alias);

        return $this;
    }

    public function set($key, $value)
    {
        $this->queryBuilder->{__FUNCTION__}($key, $value);

        return $this;
    }

    public function where($predicates)
    {
        $this->queryBuilder->{__FUNCTION__}($predicates);

        return $this;
    }

    public function andWhere($where)
    {
        $this->queryBuilder->{__FUNCTION__}($where);

        return $this;
    }

    public function orWhere($where)
    {
        $this->queryBuilder->{__FUNCTION__}($where);

        return $this;
    }

    public function groupBy($groupBy)
    {
        $this->queryBuilder->{__FUNCTION__}($groupBy);

        return $this;
    }

    public function addGroupBy($groupBy)
    {
        $this->queryBuilder->{__FUNCTION__}($groupBy);

        return $this;
    }

    public function having($having)
    {
        $this->queryBuilder->{__FUNCTION__}($having);

        return $this;
    }

    public function setFirstResult($firstResult)
    {
        $this->queryBuilder->{__FUNCTION__}($firstResult);

        return $this;
    }

    public function setMaxResults($maxResults)
    {
        $this->queryBuilder->{__FUNCTION__}($maxResults);

        return $this;
    }

    public function setParameter($key, $value, $type = null)
    {
        $this->queryBuilder->{__FUNCTION__}($key, $value, $type);

        return $this;
    }

    public function setParameters(array $params, array $types = [])
    {
        $this->queryBuilder->{__FUNCTION__}($params, $types);

        return $this;
    }

    public function __clone()
    {
        $this->queryBuilder->{__FUNCTION__}();

        return $this;
    }

    public function __toString()
    {
        $this->queryBuilder->{__FUNCTION__}();

        return $this;
    }

    public function expr()
    {
        $this->queryBuilder->{__FUNCTION__}();

        return $this;
    }

    public function resetQueryParts($queryPartNames = null)
    {
        $this->queryBuilder->{__FUNCTION__}($queryPartNames);

        return $this;
    }

    public function getQueryPart($queryPartName)
    {
        $this->queryBuilder->{__FUNCTION__}($queryPartName);

        return $this;
    }

    public function getSQL()
    {
        return $this->queryBuilder->{__FUNCTION__}();
    }

    public function getType()
    {
        return $this->queryBuilder->{__FUNCTION__}();
    }

    public function getState()
    {
        return $this->queryBuilder->{__FUNCTION__}();
    }

    public function execute()
    {
        return $this->queryBuilder->{__FUNCTION__}();
    }

    public function executeQuery(): Result
    {
        $name = method_exists($this->queryBuilder, __FUNCTION__) ? __FUNCTION__ : 'execute';

        return $this->queryBuilder->{$name}();
    }

    public function executeStatement(): int
    {
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
