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
    public function __construct(private QueryBuilder $queryBuilder)
    {
    }

    // override all public methods from parent class with empty body
    public function select($select = null): self
    {
        $this->queryBuilder->{__FUNCTION__}(...func_get_args());

        return $this;
    }

    public function from($from, $alias = null): self
    {
        $this->queryBuilder->{__FUNCTION__}($from, $alias);

        return $this;
    }

    public function addSelect($select = null): self
    {
        $this->queryBuilder->{__FUNCTION__}(...func_get_args());

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

    public function where($predicates): self
    {
        $this->queryBuilder->{__FUNCTION__}($predicates);

        return $this;
    }

    public function andWhere($where): self
    {
        $this->queryBuilder->{__FUNCTION__}($where);

        return $this;
    }

    public function orWhere($where): self
    {
        $this->queryBuilder->{__FUNCTION__}($where);

        return $this;
    }

    public function groupBy($groupBy): self
    {
        $this->queryBuilder->{__FUNCTION__}($groupBy);

        return $this;
    }

    public function addGroupBy($groupBy): self
    {
        $this->queryBuilder->{__FUNCTION__}($groupBy);

        return $this;
    }

    public function having($having): self
    {
        $this->queryBuilder->{__FUNCTION__}($having);

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
